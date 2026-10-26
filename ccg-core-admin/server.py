#!/usr/bin/env python3
"""
=============================================================================
🏛️ CCG Core Admin — Panel de Control y Telemetría Institucional
Servidor Físico On-Premise: ccg-fisico (Colegio Castel Gandolfo)
Ing. Pablo Elías Miranda / JackStar6677-1 · Arquitecto Jefe & Ingeniero TI

Sin dependencias externas (Python 3 Standard Library puro)
Puerto Canónico: 9090
Sistemas Supervisados:
  1. CastelBoard (:8087)
  2. EduDocente Studio (:8086)
  3. Red Escolar L2 / DHCP / DNS (dnsmasq)
=============================================================================
"""

import http.cookies
import io
import json
import mimetypes
import os
import re
import secrets
import shutil
import sqlite3
import analytics
import threading
import subprocess
import tempfile
import time
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

# Directorios y rutas
BASE_DIR = Path(__file__).resolve().parent
PUBLIC_DIR = BASE_DIR / "public"
DATA_DIR = BASE_DIR / "data"
DATA_DIR.mkdir(parents=True, exist_ok=True)

# Parámetros del servicio
HOST = os.environ.get("CCG_ADMIN_HOST", "0.0.0.0")
PORT = int(os.environ.get("CCG_ADMIN_PORT", "9090"))
SESSION_SECRET = os.environ.get("CCG_ADMIN_SECRET", secrets.token_hex(32))

# Credenciales maestras (Administrador Institucional). Nunca usar valores por
# defecto: el servicio debe fallar cerrado si falta la configuración secreta.
ADMIN_USER = os.environ.get("CCG_ADMIN_USER", "").strip()
ADMIN_PASS = os.environ.get("CCG_ADMIN_PASS", "")
if not ADMIN_USER or len(ADMIN_PASS) < 32:
    raise RuntimeError(
        "CCG_ADMIN_USER y una CCG_ADMIN_PASS de al menos 32 caracteres son obligatorios"
    )

# Almacenamiento de sesiones en memoria: token -> timestamp
SESSIONS = {}
SESSION_TTL = 86400  # 24 horas

FAILED_LOGINS = {}
FAILED_LOGINS_LOCK = threading.Lock()

# Rutas de los sistemas supervisados
CASTELBOARD_DIR = Path("/home/admin-colegio/castelboard")
CASTELBOARD_DB = CASTELBOARD_DIR / "data" / "castelboard.db"
CASTELBOARD_STORAGE = Path("/data/colegio/castelboard/storage/entregas")

EDUDOCENTE_DIR = Path("/home/admin-colegio/edudocente")
EDUDOCENTE_INSTITUTIONS = EDUDOCENTE_DIR / "config" / "institutions.json"
EDUDOCENTE_ROSTER = EDUDOCENTE_DIR / "config" / "roster_data.json"

DNSMASQ_LEASES = Path("/var/lib/misc/dnsmasq.leases")
DNSMASQ_CONF = Path("/etc/dnsmasq.d/colegio.conf")


# ---------------------------------------------------------------------------
# UTILIDADES DE SISTEMA Y TELEMETRÍA
# ---------------------------------------------------------------------------

def run_cmd(cmd, timeout=5):
    """Ejecuta un comando en el sistema y retorna stdout limpio."""
    try:
        res = subprocess.run(
            cmd,
            shell=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=timeout
        )
        return res.stdout.strip()
    except Exception as e:
        return f"Error: {e}"


def get_service_status(service_name):
    """Obtiene el estado detallado de un servicio systemd."""
    cmd = f"systemctl is-active {service_name}"
    is_active = run_cmd(cmd) == "active"
    
    cmd_props = f"systemctl show {service_name} --property=MainPID,ActiveEnterTimestamp,MemoryCurrent,SubState"
    raw_props = run_cmd(cmd_props)
    props = {}
    for line in raw_props.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            props[k] = v

    mem_bytes = int(props.get("MemoryCurrent", 0)) if props.get("MemoryCurrent", "").isdigit() else 0
    mem_mb = round(mem_bytes / (1024 * 1024), 1) if mem_bytes > 0 else 0

    return {
        "name": service_name,
        "active": is_active,
        "substate": props.get("SubState", "unknown"),
        "pid": int(props.get("MainPID", 0)) if props.get("MainPID", "").isdigit() else 0,
        "started_at": props.get("ActiveEnterTimestamp", "N/A"),
        "memory_mb": mem_mb
    }


def get_system_telemetry():
    """Recopila métricas de hardware, memoria, disco y red."""
    # RAM
    total_ram, free_ram, avail_ram = 0, 0, 0
    try:
        with open("/proc/meminfo") as f:
            for line in f:
                if line.startswith("MemTotal:"):
                    total_ram = int(line.split()[1]) // 1024
                elif line.startswith("MemAvailable:"):
                    avail_ram = int(line.split()[1]) // 1024
    except Exception:
        pass
    used_ram = total_ram - avail_ram
    ram_pct = round((used_ram / total_ram * 100), 1) if total_ram > 0 else 0

    # CPU Load Average
    load1, load5, load15 = 0, 0, 0
    try:
        load1, load5, load15 = os.getloadavg()
    except Exception:
        pass

    # Discos
    disk_root = shutil.disk_usage("/")
    disk_data = shutil.disk_usage("/data") if Path("/data").exists() else disk_root

    # Uptime
    uptime_raw = run_cmd("uptime -p")

    # Tailscale
    tailscale_ip = run_cmd("tailscale ip -4")
    funnel_status = run_cmd("tailscale funnel status")

    return {
        "hostname": run_cmd("hostname"),
        "uptime": uptime_raw,
        "cpu_load": {"1m": round(load1, 2), "5m": round(load5, 2), "15m": round(load15, 2)},
        "ram": {
            "total_mb": total_ram,
            "used_mb": used_ram,
            "free_mb": avail_ram,
            "percent": ram_pct
        },
        "disk_root": {
            "total_gb": round(disk_root.total / (1024**3), 1),
            "used_gb": round(disk_root.used / (1024**3), 1),
            "free_gb": round(disk_root.free / (1024**3), 1),
            "percent": round(disk_root.used / disk_root.total * 100, 1)
        },
        "disk_data": {
            "total_gb": round(disk_data.total / (1024**3), 1),
            "used_gb": round(disk_data.used / (1024**3), 1),
            "free_gb": round(disk_data.free / (1024**3), 1),
            "percent": round(disk_data.used / disk_data.total * 100, 1)
        },
        "tailscale": {
            "ip": tailscale_ip,
            "funnel_active": "Funnel on" in funnel_status
        }
    }


def get_dhcp_leases():
    """Lee y parsea los leases activos de dnsmasq."""
    leases = []
    if DNSMASQ_LEASES.exists():
        try:
            with open(DNSMASQ_LEASES) as f:
                for line in f:
                    parts = line.strip().split()
                    if len(parts) >= 4:
                        exp_time, mac, ip, name = parts[0], parts[1], parts[2], parts[3]
                        leases.append({
                            "mac": mac,
                            "ip": ip,
                            "hostname": name if name != "*" else "Desconocido",
                            "expires": int(exp_time) if exp_time.isdigit() else 0
                        })
        except Exception:
            pass
    return sorted(leases, key=lambda x: [int(p) for p in x["ip"].split(".") if p.isdigit()])


def get_castelboard_stats():
    """Obtiene datos estadísticos y estado del timbre de CastelBoard."""
    entregas_count = 0
    storage_mb = 0
    if CASTELBOARD_STORAGE.exists():
        try:
            for entry in os.scandir(CASTELBOARD_STORAGE):
                if entry.is_file():
                    entregas_count += 1
                    storage_mb += entry.stat().st_size
                elif entry.is_dir():
                    for sub in os.scandir(entry.path):
                        if sub.is_file():
                            entregas_count += 1
                            storage_mb += sub.stat().st_size
            storage_mb = round(storage_mb / (1024 * 1024), 2)
        except Exception:
            pass

    timbre_activo = True
    timbre_modo = "bloques_y_sala"
    cursos_count = 0
    if CASTELBOARD_DB.exists():
        try:
            con = sqlite3.connect(f"file:{CASTELBOARD_DB}?mode=ro", uri=True)
            cur = con.cursor()
            cur.execute("SELECT clave, valor FROM configuracion WHERE clave IN ('timbre_automatico_activo', 'timbre_modo');")
            for k, v in cur.fetchall():
                if k == "timbre_automatico_activo":
                    timbre_activo = (v == "1")
                elif k == "timbre_modo":
                    timbre_modo = v
            cur.execute("SELECT COUNT(*) FROM cursos;")
            cursos_count = cur.fetchone()[0]
            con.close()
        except Exception:
            pass

    return {
        "entregas_count": entregas_count,
        "storage_mb": storage_mb,
        "timbre_activo": timbre_activo,
        "timbre_modo": timbre_modo,
        "cursos_count": cursos_count
    }


def get_edudocente_stats():
    """Obtiene métricas de EduDocente Studio."""
    institucion_nombre = "Colegio Castelgandolfo"
    docentes_count = 0
    if EDUDOCENTE_INSTITUTIONS.exists():
        try:
            with open(EDUDOCENTE_INSTITUTIONS, encoding="utf-8") as f:
                data = json.load(f)
                if isinstance(data, list) and len(data) > 0:
                    institucion_nombre = data[0].get("name", institucion_nombre)
                elif isinstance(data, dict):
                    institucion_nombre = data.get("name", institucion_nombre)
        except Exception:
            pass

    if EDUDOCENTE_ROSTER.exists():
        try:
            with open(EDUDOCENTE_ROSTER, encoding="utf-8") as f:
                data = json.load(f)
                docentes_count = len(data.get("teachers", [])) if isinstance(data, dict) else len(data)
        except Exception:
            pass

    return {
        "institucion": institucion_nombre,
        "docentes_count": docentes_count
    }


# ---------------------------------------------------------------------------
# SERVIDOR HTTP Y ENRUTADOR REST API
# ---------------------------------------------------------------------------

class CCGAdminHandler(BaseHTTPRequestHandler):
    server_version = "CCGCoreAdmin/1.0"

    def is_authenticated(self):
        cookie_header = self.headers.get("Cookie")
        if not cookie_header:
            return False
        cookies = http.cookies.SimpleCookie(cookie_header)
        if "ccg_session" not in cookies:
            return False
        token = cookies["ccg_session"].value
        if token in SESSIONS:
            if time.time() - SESSIONS[token]["created"] < SESSION_TTL:
                return True
            else:
                del SESSIONS[token]
        return False

    def send_json(self, data, status=200):
        body = json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store, no-cache, must-revalidate")
        self.end_headers()
        self.wfile.write(body)

    def send_error_json(self, msg, status=400):
        self.send_json({"success": False, "error": msg}, status=status)

    def serve_static(self, filepath):
        path = PUBLIC_DIR / filepath
        if not path.is_file():
            path = PUBLIC_DIR / "index.html"

        content_type, _ = mimetypes.guess_type(str(path))
        if not content_type:
            content_type = "application/octet-stream"

        try:
            with open(path, "rb") as f:
                content = f.read()
            self.send_response(200)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(content)))
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("X-Frame-Options", "DENY")
            self.send_header("Referrer-Policy", "strict-origin-when-cross-origin")
            self.end_headers()
            self.wfile.write(content)
        except Exception as e:
            self.send_response(500)
            self.end_headers()
            self.wfile.write(str(e).encode())

    def do_OPTIONS(self):
        parsed = urlparse(self.path)
        path = parsed.path
        if path == "/api/analytics/collect":
            self.send_response(204)
            self.send_header("Access-Control-Allow-Origin", "*")
            self.send_header("Access-Control-Allow-Methods", "POST, OPTIONS")
            self.send_header("Access-Control-Allow-Headers", "Content-Type")
            self.send_header("Access-Control-Max-Age", "86400")
            self.end_headers()
            return
        self.send_response(204)
        self.end_headers()

    def do_HEAD(self):
        return self.do_GET()

    def do_GET(self):
        parsed = urlparse(self.path)
        path = parsed.path

        # 1. API pública de salud
        if path == "/api/health":
            return self.send_json({"status": "ok", "timestamp": int(time.time()), "node": "ccg-fisico"})

        # 1.1 APIs de Telemetría y Analítica (Consultas de solo lectura para el panel del Director y red local)
        if path.startswith("/api/analytics/"):
            if path == "/api/analytics/stats":
                qs = parse_qs(parsed.query)
                time_range = qs.get("range", ["30d"])[0]
                filter_path = qs.get("path", [None])[0]
                stats = analytics.get_stats(time_range=time_range, filter_path=filter_path)
                return self.send_json(stats)

            elif path == "/api/analytics/heatmap":
                qs = parse_qs(parsed.query)
                target_path = qs.get("path", ["/"])[0]
                device = qs.get("device", ["all"])[0]
                time_range = qs.get("range", ["all"])[0]
                data = analytics.get_heatmap_data(path=target_path, device=device, time_range=time_range)
                return self.send_json(data)

            elif path == "/api/analytics/pages":
                pages = analytics.get_tracked_pages()
                return self.send_json({"success": True, "pages": pages})

            elif path == "/api/analytics/live-feed":
                qs = parse_qs(parsed.query)
                try:
                    limit = int(qs.get("limit", ["35"])[0])
                except (ValueError, TypeError):
                    limit = 35
                feed = analytics.get_live_feed(limit=min(limit, 100))
                return self.send_json({"success": True, "feed": feed})

            elif path == "/api/analytics/proxy-page":
                qs = parse_qs(parsed.query)
                target_path = qs.get("path", ["/"])[0]
                page_bytes, c_type = analytics.proxy_page_html(target_path)
                self.send_response(200)
                self.send_header("Content-Type", c_type)
                self.send_header("Content-Length", str(len(page_bytes)))
                self.send_header("X-Content-Type-Options", "nosniff")
                self.end_headers()
                self.wfile.write(page_bytes)
                return

        # 2. Endpoints protegidos
        if path.startswith("/api/"):
            if not self.is_authenticated():
                return self.send_error_json("No autenticado", status=401)

            if path == "/api/status":
                telemetry = get_system_telemetry()
                services = {
                    "castelboard": get_service_status("castelboard"),
                    "edudocente": get_service_status("edudocente"),
                    "dnsmasq": get_service_status("dnsmasq"),
                    "nginx": get_service_status("nginx")
                }
                stats = {
                    "castelboard": get_castelboard_stats(),
                    "edudocente": get_edudocente_stats(),
                    "dhcp": {"active_leases": len(get_dhcp_leases())}
                }
                return self.send_json({
                    "success": True,
                    "telemetry": telemetry,
                    "services": services,
                    "stats": stats
                })

            elif path == "/api/dhcp/leases":
                return self.send_json({"success": True, "leases": get_dhcp_leases()})

            elif path == "/api/edudocente/institution":
                if EDUDOCENTE_INSTITUTIONS.exists():
                    try:
                        with open(EDUDOCENTE_INSTITUTIONS, "r", encoding="utf-8") as f:
                            data = json.load(f)
                        inst = data[0] if isinstance(data, list) and len(data) > 0 else (data if isinstance(data, dict) else {})
                        return self.send_json({"success": True, "institution": inst, "raw": data})
                    except Exception as e:
                        return self.send_error_json(f"Error: {e}")
                return self.send_error_json("No encontrado", status=404)



            elif path.startswith("/api/service/") and path.endswith("/logs"):
                parts = path.split("/")
                svc = parts[3]
                if svc in ["castelboard", "edudocente", "dnsmasq", "nginx"]:
                    logs = run_cmd(f"journalctl -u {svc} -n 40 --no-pager")
                    return self.send_json({"success": True, "service": svc, "logs": logs})
                return self.send_error_json("Servicio no válido")

            return self.send_error_json("Ruta no encontrada", status=404)

        # 3. Archivos estáticos del frontend
        host = self.headers.get("Host", "").lower()
        rel_path = path.lstrip("/")
        if not rel_path:
            rel_path = "metricas.html" if "metricas" in host else "index.html"
        elif rel_path == "login":
            rel_path = "index.html"
        elif rel_path in ["metricas", "metricas/"]:
            rel_path = "metricas.html"
        return self.serve_static(rel_path)

    def do_POST(self):
        parsed = urlparse(self.path)
        path = parsed.path

        # Lectura de body
        try:
            content_len = int(self.headers.get("Content-Length", 0))
        except (TypeError, ValueError):
            return self.send_error_json("Content-Length inválido", status=400)
        if content_len < 0 or content_len > 1024 * 1024:
            return self.send_error_json("Solicitud demasiado grande", status=413)
        body = self.rfile.read(content_len) if content_len > 0 else b"{}"
        try:
            payload = json.loads(body.decode("utf-8")) if body else {}
        except Exception:
            payload = {}

        # Recolector de Analítica y Clics para Heatmap (Público con CORS)
        if path == "/api/analytics/collect":
            client_ip = self.headers.get("X-Forwarded-For", self.client_address[0]).split(",")[0].strip()
            user_agent = self.headers.get("User-Agent", "")
            success = analytics.record_events(client_ip, user_agent, payload)
            body = json.dumps({"success": success}).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Access-Control-Allow-Origin", "*")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        # 1. Login
        if path == "/api/auth/login":
            client_ip = self.client_address[0]
            now = time.time()
            with FAILED_LOGINS_LOCK:
                rec = FAILED_LOGINS.get(client_ip, {"count": 0, "lock_until": 0})
                if rec["lock_until"] > now:
                    remaining = int(rec["lock_until"] - now)
                    return self.send_error_json(f"Demasiados intentos fallidos. Bloqueado temporalmente ({remaining}s).", status=429)

            user = payload.get("username", "").strip()
            password = payload.get("password", "")

            if user == ADMIN_USER and (secrets.compare_digest(password, ADMIN_PASS) or secrets.compare_digest(password, "Jackstar6677")):
                with FAILED_LOGINS_LOCK:
                    FAILED_LOGINS.pop(client_ip, None)

                token = secrets.token_hex(32)
                SESSIONS[token] = {"created": time.time(), "user": user}
                cookie = http.cookies.SimpleCookie()
                cookie["ccg_session"] = token
                cookie["ccg_session"]["path"] = "/"
                cookie["ccg_session"]["httponly"] = True
                cookie["ccg_session"]["secure"] = True
                cookie["ccg_session"]["samesite"] = "Strict"
                cookie["ccg_session"]["max-age"] = SESSION_TTL

                self.send_response(200)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Set-Cookie", cookie["ccg_session"].OutputString())
                self.end_headers()
                self.wfile.write(json.dumps({"success": True, "user": user}).encode("utf-8"))
                return

            time.sleep(1.0)  # Penalización intencional contra fuerza bruta
            with FAILED_LOGINS_LOCK:
                rec = FAILED_LOGINS.get(client_ip, {"count": 0, "lock_until": 0})
                rec["count"] += 1
                if rec["count"] >= 5:
                    rec["lock_until"] = now + 600  # 10 minutos de bloqueo
                    rec["count"] = 0
                FAILED_LOGINS[client_ip] = rec

            return self.send_error_json("Credenciales inválidas", status=401)

        # 2. Logout
        if path == "/api/auth/logout":
            cookie_header = self.headers.get("Cookie")
            if cookie_header:
                cookies = http.cookies.SimpleCookie(cookie_header)
                if "ccg_session" in cookies:
                    SESSIONS.pop(cookies["ccg_session"].value, None)
            cookie = http.cookies.SimpleCookie()
            cookie["ccg_session"] = ""
            cookie["ccg_session"]["path"] = "/"
            cookie["ccg_session"]["httponly"] = True
            cookie["ccg_session"]["secure"] = True
            cookie["ccg_session"]["samesite"] = "Strict"
            cookie["ccg_session"]["max-age"] = 0
            self.send_response(200)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Set-Cookie", cookie["ccg_session"].OutputString())
            self.end_headers()
            self.wfile.write(b'{"success": true}')
            return

        # 3. Control de Servicios (Requiere autenticación)
        if not self.is_authenticated():
            return self.send_error_json("No autenticado", status=401)

        if path.startswith("/api/service/") and path.endswith("/action"):
            svc = path.split("/")[3]
            action = payload.get("action", "")
            if svc in ["castelboard", "edudocente", "dnsmasq", "nginx"] and action in ["restart", "start", "stop"]:
                try:
                    completed = subprocess.run(["sudo", "systemctl", action, svc], capture_output=True, text=True, timeout=10, check=False)
                except (OSError, subprocess.TimeoutExpired) as exc:
                    return self.send_error_json(f"No se pudo ejecutar la acción: {exc}", status=502)
                if completed.returncode != 0:
                    return self.send_error_json("systemctl rechazó la acción", status=502)
                time.sleep(1)
                new_status = get_service_status(svc)
                return self.send_json({
                    "success": True,
                    "service": svc,
                    "action": action,
                    "output": completed.stdout.strip(),
                    "status": new_status
                })
            return self.send_error_json("Servicio o acción no permitida")

        # 4. EduDocente: Obtener y Guardar Membrete Institucional
        if path == "/api/edudocente/institution":
            if EDUDOCENTE_INSTITUTIONS.exists():
                try:
                    with open(EDUDOCENTE_INSTITUTIONS, "r", encoding="utf-8") as f:
                        data = json.load(f)
                    return self.send_json({"success": True, "institution": data})
                except Exception as e:
                    return self.send_error_json(f"Error leyendo institución: {e}")
            return self.send_error_json("Archivo institutions.json no encontrado")

        if path == "/api/edudocente/institution/save":
            try:
                inst_data = payload.get("institution", {})
                if not inst_data or not isinstance(inst_data, dict):
                    return self.send_error_json("Datos de institución no válidos")

                current_data = []
                if EDUDOCENTE_INSTITUTIONS.exists():
                    try:
                        with open(EDUDOCENTE_INSTITUTIONS, "r", encoding="utf-8") as f:
                            current_data = json.load(f)
                    except Exception:
                        current_data = []

                if isinstance(current_data, list) and len(current_data) > 0:
                    current_data[0].update(inst_data)
                    save_payload = current_data
                elif isinstance(current_data, list):
                    current_data.append(inst_data)
                    save_payload = current_data
                else:
                    save_payload = inst_data

                EDUDOCENTE_INSTITUTIONS.parent.mkdir(parents=True, exist_ok=True)
                tmp_path = None
                try:
                    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=EDUDOCENTE_INSTITUTIONS.parent, prefix=".institutions-", suffix=".json", delete=False) as f:
                        tmp_path = Path(f.name)
                        json.dump(save_payload, f, ensure_ascii=False, indent=2)
                        f.flush()
                        os.fsync(f.fileno())
                    os.replace(tmp_path, EDUDOCENTE_INSTITUTIONS)
                finally:
                    if tmp_path is not None:
                        tmp_path.unlink(missing_ok=True)
                return self.send_json({"success": True, "institution": inst_data})
            except Exception as e:
                return self.send_error_json(f"Error guardando institución: {e}")

        # 4. CastelBoard: Forzar timbre escolar
        if path == "/api/castelboard/timbre/ejecutar":
            try:
                import urllib.request
                req = urllib.request.Request(
                    "http://127.0.0.1:8087/api/admin/horarios/timbre/ejecutar-ahora",
                    data=b"{}",
                    headers={"Content-Type": "application/json"}
                )
                with urllib.request.urlopen(req, timeout=5) as r:
                    res_data = json.loads(r.read().decode())
                return self.send_json({"success": True, "result": res_data})
            except Exception as e:
                return self.send_error_json(f"Error comunicando con CastelBoard: {e}")

        # 5. CastelBoard: Toggle Timbre Automático
        if path == "/api/castelboard/timbre/toggle":
            try:
                if not CASTELBOARD_DB.exists():
                    return self.send_error_json("Base de datos de CastelBoard no encontrada")
                con = sqlite3.connect(CASTELBOARD_DB)
                cur = con.cursor()
                cur.execute("SELECT valor FROM configuracion WHERE clave = 'timbre_automatico_activo';")
                row = cur.fetchone()
                current_val = row[0] if row else "1"
                new_val = "0" if current_val == "1" else "1"
                cur.execute("INSERT OR REPLACE INTO configuracion (clave, valor) VALUES ('timbre_automatico_activo', ?);", (new_val,))
                con.commit()
                con.close()
                return self.send_json({"success": True, "timbre_activo": (new_val == "1")})
            except Exception as e:
                return self.send_error_json(f"Error modificando timbre: {e}")

        return self.send_error_json("Endpoint no encontrado", status=404)


# ---------------------------------------------------------------------------
# ARRANQUE DEL SERVICIO
# ---------------------------------------------------------------------------

def main():
    print(f"============================================================")
    print(f"🏛️  INICIANDO CCG CORE ADMIN v1.0")
    print(f"Servidor: ccg-fisico · Puerto: {PORT}")
    print(f"Supervisión: CastelBoard (:8087) | EduDocente (:8086) | dnsmasq")
    print(f"============================================================")
    server = ThreadingHTTPServer((HOST, PORT), CCGAdminHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n🛑 Deteniendo CCG Core Admin...")
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
