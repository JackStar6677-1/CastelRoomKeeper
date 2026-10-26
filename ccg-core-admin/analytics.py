"""
=============================================================================
🏛️ CCG Analytics Engine — Analítica Soberana On-Premise y Mapas de Calor
Servidor Físico On-Premise: ccg-fisico (Colegio Castel Gandolfo)
Ing. Pablo Elías Miranda / JackStar6677-1 · Arquitecto Jefe & Ingeniero TI

Base de datos: SQLite WAL (/home/admin-colegio/ccg-core-admin/data/castel_analytics.db)
Privacidad: Hasheado diario con Salt de IPs (Cero PII / Protección de Menores)
Rendimiento: Agrupación espacial para mapas de calor a 60 FPS
=============================================================================
"""

import hashlib
import json
import os
import re
import sqlite3
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = BASE_DIR / "data"
DATA_DIR.mkdir(parents=True, exist_ok=True)
DB_PATH = DATA_DIR / "castel_analytics.db"
SALT_FILE = DATA_DIR / ".analytics_salt"

# Inicializar salt persistente para hash diario de visitantes
if not SALT_FILE.exists():
    SALT_FILE.write_text(os.urandom(32).hex(), encoding="utf-8")
try:
    SALT = SALT_FILE.read_text(encoding="utf-8").strip()
except Exception:
    SALT = "ccg-default-salt-2026"


def get_db_connection():
    """Retorna una conexión a SQLite optimizada para lecturas y escrituras concurrentes (WAL)."""
    conn = sqlite3.connect(str(DB_PATH), timeout=10.0)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode = WAL;")
    conn.execute("PRAGMA synchronous = NORMAL;")
    conn.execute("PRAGMA temp_store = MEMORY;")
    return conn


def init_db():
    """Crea las tablas e índices si no existen."""
    with get_db_connection() as conn:
        conn.executescript("""
            CREATE TABLE IF NOT EXISTS pageviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path TEXT NOT NULL,
                visitor_hash TEXT NOT NULL,
                session_id TEXT,
                referrer TEXT,
                device TEXT,
                browser TEXT,
                os TEXT,
                screen_w INTEGER,
                screen_h INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS clicks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path TEXT NOT NULL,
                visitor_hash TEXT NOT NULL,
                session_id TEXT,
                x_pct REAL NOT NULL,
                y_px INTEGER NOT NULL,
                element_tag TEXT,
                element_text TEXT,
                device TEXT,
                screen_w INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS scrolls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path TEXT NOT NULL,
                visitor_hash TEXT NOT NULL,
                session_id TEXT,
                max_depth INTEGER NOT NULL,
                duration_sec INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_pv_path_created ON pageviews (path, created_at);
            CREATE INDEX IF NOT EXISTS idx_pv_visitor ON pageviews (visitor_hash, created_at);
            CREATE INDEX IF NOT EXISTS idx_clicks_path ON clicks (path, created_at);
            CREATE INDEX IF NOT EXISTS idx_clicks_coords ON clicks (path, device, x_pct, y_px);
            CREATE INDEX IF NOT EXISTS idx_scrolls_path ON scrolls (path, max_depth);
        """)


def anonymize_visitor(ip, user_agent):
    """
    Genera un hash de visitante diario con salt.
    Protege la privacidad de menores y familias: es imposible rastrear IPs reales,
    pero permite contar visitantes únicos diarios de forma determinista y precisa.
    """
    today = datetime.now().strftime("%Y-%m-%d")
    raw = f"{ip}:{user_agent}:{today}:{SALT}".encode("utf-8")
    return hashlib.sha256(raw).hexdigest()[:16]


def parse_device_info(user_agent, screen_w=0):
    """Detecta dispositivo (desktop, mobile, tablet), navegador y SO."""
    ua = user_agent.lower() if user_agent else ""

    # Dispositivo
    device = "desktop"
    if "tablet" in ua or "ipad" in ua:
        device = "tablet"
    elif "mobi" in ua or "android" in ua or "iphone" in ua or (screen_w and screen_w < 768):
        device = "mobile"

    # Navegador
    browser = "Otro"
    if "edg/" in ua:
        browser = "Edge"
    elif "chrome/" in ua or "crios/" in ua:
        browser = "Chrome"
    elif "firefox/" in ua or "fxios/" in ua:
        browser = "Firefox"
    elif "safari/" in ua and "chrome" not in ua:
        browser = "Safari"
    elif "opera" in ua or "opr/" in ua:
        browser = "Opera"

    # Sistema Operativo
    os_name = "Otro"
    if "windows" in ua:
        os_name = "Windows"
    elif "android" in ua:
        os_name = "Android"
    elif "iphone" in ua or "ipad" in ua or "ios" in ua:
        os_name = "iOS"
    elif "macintosh" in ua or "mac os" in ua:
        os_name = "macOS"
    elif "linux" in ua:
        os_name = "Linux"

    return device, browser, os_name


def clean_path(raw_path):
    """Normaliza rutas para agrupar métricas (ej. /admision2027/index.html -> /admision2027/)."""
    if not raw_path:
        return "/"
    p = raw_path.strip().split("?")[0].split("#")[0]
    if not p.startswith("/"):
        p = "/" + p
    if p.endswith("/index.html") or p.endswith("/index.php"):
        p = p.rsplit("index.", 1)[0]
    if len(p) > 1 and not p.endswith("/"):
        p += "/"
    return p[:150]


def record_events(client_ip, user_agent, payload):
    """Registra uno o múltiples eventos de telemetría y mapa de calor."""
    if not isinstance(payload, dict):
        return False

    events = payload.get("events")
    if not isinstance(events, list):
        events = [payload]

    visitor_hash = anonymize_visitor(client_ip, user_agent)
    conn = get_db_connection()
    try:
        with conn:
            for ev in events:
                ev_type = ev.get("type", "").lower()
                raw_path = ev.get("path") or ev.get("url") or "/"
                path = clean_path(raw_path)
                session_id = str(ev.get("session_id", ""))[:32]
                screen_w = int(ev.get("screen_w", 0) or 0)
                screen_h = int(ev.get("screen_h", 0) or 0)
                device_in = ev.get("device")

                detected_device, browser, os_name = parse_device_info(user_agent, screen_w)
                device = device_in if device_in in ["desktop", "mobile", "tablet"] else detected_device

                if ev_type == "pageview":
                    referrer = str(ev.get("referrer", ""))[:200]
                    conn.execute("""
                        INSERT INTO pageviews (path, visitor_hash, session_id, referrer, device, browser, os, screen_w, screen_h)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    """, (path, visitor_hash, session_id, referrer, device, browser, os_name, screen_w, screen_h))

                elif ev_type == "click":
                    try:
                        x_pct = float(ev.get("x_pct", 0.0))
                        y_px = int(float(ev.get("y_px", 0)))
                    except (ValueError, TypeError):
                        continue
                    if 0.0 <= x_pct <= 100.0 and y_px >= 0:
                        tag = str(ev.get("tag", ""))[:20].upper()
                        text = str(ev.get("text", "")).strip()[:60]
                        conn.execute("""
                            INSERT INTO clicks (path, visitor_hash, session_id, x_pct, y_px, element_tag, element_text, device, screen_w)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        """, (path, visitor_hash, session_id, x_pct, y_px, tag, text, device, screen_w))

                elif ev_type == "scroll":
                    try:
                        max_depth = int(ev.get("max_depth", 0))
                        duration_sec = int(ev.get("duration_sec", 0))
                    except (ValueError, TypeError):
                        continue
                    if max_depth in [25, 50, 75, 100]:
                        conn.execute("""
                            INSERT INTO scrolls (path, visitor_hash, session_id, max_depth, duration_sec)
                            VALUES (?, ?, ?, ?, ?)
                        """, (path, visitor_hash, session_id, max_depth, duration_sec))
        return True
    finally:
        conn.close()


def get_range_condition(time_range):
    """Genera cláusula SQL de filtrado temporal según el selector."""
    if time_range == "today":
        return "created_at >= date('now', 'localtime', 'start of day')"
    elif time_range == "7d":
        return "created_at >= datetime('now', 'localtime', '-7 days')"
    elif time_range == "30d":
        return "created_at >= datetime('now', 'localtime', '-30 days')"
    elif time_range == "90d":
        return "created_at >= datetime('now', 'localtime', '-90 days')"
    return "1=1"


def get_stats(time_range="30d", filter_path=None):
    """Genera resumen ejecutivo de métricas institucionales y comportamiento de apoderados."""
    time_cond = get_range_condition(time_range)
    path_cond = "path = ?" if filter_path else "1=1"
    params = [filter_path] if filter_path else []

    conn = get_db_connection()
    try:
        # Total pageviews y visitantes únicos
        pv_row = conn.execute(f"""
            SELECT COUNT(*) AS total_views, COUNT(DISTINCT visitor_hash) AS unique_visitors
            FROM pageviews
            WHERE {time_cond} AND {path_cond}
        """, params).fetchone()

        total_views = pv_row["total_views"] if pv_row else 0
        unique_visitors = pv_row["unique_visitors"] if pv_row else 0

        # Clics totales
        cl_row = conn.execute(f"""
            SELECT COUNT(*) AS total_clicks
            FROM clicks
            WHERE {time_cond} AND {path_cond}
        """, params).fetchone()
        total_clicks = cl_row["total_clicks"] if cl_row else 0

        # Top páginas
        top_pages = []
        if not filter_path:
            rows = conn.execute(f"""
                SELECT path, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as uniques
                FROM pageviews
                WHERE {time_cond}
                GROUP BY path
                ORDER BY views DESC
                LIMIT 15
            """).fetchall()
            top_pages = [{"path": r["path"], "views": r["views"], "uniques": r["uniques"]} for r in rows]

        # Distribución de dispositivos
        dev_rows = conn.execute(f"""
            SELECT device, COUNT(*) as count
            FROM pageviews
            WHERE {time_cond} AND {path_cond}
            GROUP BY device
            ORDER BY count DESC
        """, params).fetchall()
        devices = {r["device"]: r["count"] for r in dev_rows}

        # Fuentes / Referrers principales
        ref_rows = conn.execute(f"""
            SELECT 
                CASE 
                    WHEN referrer = '' OR referrer IS NULL THEN 'Directo'
                    WHEN referrer LIKE '%google%' THEN 'Google'
                    WHEN referrer LIKE '%instagram%' THEN 'Instagram'
                    WHEN referrer LIKE '%facebook%' THEN 'Facebook'
                    WHEN referrer LIKE '%colegiocastelgandolfo.cl%' THEN 'Navegación Interna'
                    ELSE substr(referrer, 1, 40)
                END as source,
                COUNT(*) as count
            FROM pageviews
            WHERE {time_cond} AND {path_cond}
            GROUP BY source
            ORDER BY count DESC
            LIMIT 8
        """, params).fetchall()
        referrers = [{"source": r["source"], "count": r["count"]} for r in ref_rows]

        # Profundidad de Scroll
        scroll_rows = conn.execute(f"""
            SELECT max_depth, COUNT(*) as count
            FROM scrolls
            WHERE {time_cond} AND {path_cond}
            GROUP BY max_depth
            ORDER BY max_depth ASC
        """, params).fetchall()
        scroll_breakdown = {f"{r['max_depth']}%": r["count"] for r in scroll_rows}

        # Tendencia Diaria (últimos 14 días)
        trend_rows = conn.execute(f"""
            SELECT date(created_at, 'localtime') as day, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as uniques
            FROM pageviews
            WHERE created_at >= datetime('now', 'localtime', '-14 days') AND {path_cond}
            GROUP BY day
            ORDER BY day ASC
        """, params).fetchall()
        daily_trend = [{"day": r["day"], "views": r["views"], "uniques": r["uniques"]} for r in trend_rows]

        return {
            "success": True,
            "range": time_range,
            "filter_path": filter_path,
            "summary": {
                "total_views": total_views,
                "unique_visitors": unique_visitors,
                "total_clicks": total_clicks,
                "clicks_per_view": round(total_clicks / total_views, 2) if total_views > 0 else 0.0
            },
            "top_pages": top_pages,
            "devices": devices,
            "referrers": referrers,
            "scroll_breakdown": scroll_breakdown,
            "daily_trend": daily_trend
        }
    finally:
        conn.close()


def get_heatmap_data(path, device="all", time_range="all"):
    """
    Retorna los puntos de calor espaciales agregados para renderizar el mapa térmico en la página indicada.
    Agrupa clics cercanos para optimizar el rendimiento y transferir datos compactos.
    """
    clean_p = clean_path(path)
    time_cond = get_range_condition(time_range)
    device_cond = "1=1"
    params = [clean_p]

    if device in ["desktop", "mobile", "tablet"]:
        device_cond = "device = ?"
        params.append(device)

    conn = get_db_connection()
    try:
        # Agrupación espacial: x_pct con precisión de 0.5%, y_px en bloques de 12px
        rows = conn.execute(f"""
            SELECT 
                ROUND(x_pct, 1) as x,
                (y_px / 12) * 12 + 6 as y,
                COUNT(*) as count,
                element_tag as tag,
                element_text as text
            FROM clicks
            WHERE path = ? AND {time_cond} AND {device_cond}
            GROUP BY x, y
            ORDER BY count DESC
            LIMIT 2500
        """, params).fetchall()

        points = []
        max_val = 1
        total_clicks = 0
        for r in rows:
            cnt = r["count"]
            if cnt > max_val:
                max_val = cnt
            total_clicks += cnt
            points.append({
                "x": float(r["x"]),
                "y": int(r["y"]),
                "val": cnt,
                "tag": r["tag"] or "",
                "text": r["text"] or ""
            })

        # Datos de scroll para esa página
        scroll_params = [clean_p]
        scroll_rows = conn.execute(f"""
            SELECT max_depth, COUNT(*) as count
            FROM scrolls
            WHERE path = ? AND {time_cond}
            GROUP BY max_depth
        """, scroll_params).fetchall()
        scroll_map = {r["max_depth"]: r["count"] for r in scroll_rows}

        # Ancho y alto de referencia capturados en pageviews recientes
        meta_row = conn.execute("""
            SELECT screen_w, screen_h
            FROM pageviews
            WHERE path = ? AND screen_w > 0
            ORDER BY created_at DESC
            LIMIT 1
        """, [clean_p]).fetchone()

        ref_w = meta_row["screen_w"] if meta_row else 1280
        ref_h = meta_row["screen_h"] if meta_row else 800

        return {
            "success": True,
            "path": clean_p,
            "device": device,
            "range": time_range,
            "total_clicks": total_clicks,
            "max_value": max_val,
            "points": points,
            "scroll_depths": scroll_map,
            "reference_screen": {"w": ref_w, "h": ref_h}
        }
    finally:
        conn.close()


def get_tracked_pages():
    """Retorna la lista de todas las rutas de la web que han recibido tráfico."""
    conn = get_db_connection()
    try:
        rows = conn.execute("""
            SELECT path, COUNT(*) as views, MAX(created_at) as last_seen
            FROM pageviews
            GROUP BY path
            ORDER BY views DESC
        """).fetchall()
        return [{"path": r["path"], "views": r["views"], "last_seen": r["last_seen"]} for r in rows]
    finally:
        conn.close()


def proxy_page_html(path):
    """
    Descarga el HTML de https://www.colegiocastelgandolfo.cl<path> y le inyecta:
    1. <base href="https://www.colegiocastelgandolfo.cl/"> para cargar recursos, fuentes e imágenes originales.
    2. Bloqueo de navegación interactiva interna para mantener la experiencia fluida dentro del visor del mapa de calor.
    Permite visualizar la página real del colegio sin ser bloqueado por X-Frame-Options: SAMEORIGIN.
    """
    target_path = clean_path(path)
    url = f"https://www.colegiocastelgandolfo.cl{target_path}"
    req = urllib.request.Request(
        url,
        headers={"User-Agent": "Mozilla/5.0 (CCG-Core-Admin Heatmap Proxy; +https://ccg-fisico.tail0e08b5.ts.net)"}
    )
    try:
        with urllib.request.urlopen(req, timeout=8.0) as resp:
            content = resp.read()
            html = content.decode("utf-8", errors="replace")

        # Inyectar <base> justo después de <head>
        base_tag = '\n<base href="https://www.colegiocastelgandolfo.cl/">\n'
        shield_script = '''
        <style>
          /* Desactivar clicks normales para que no naveguen dentro del visor */
          a, button { cursor: default !important; }
        </style>
        <script>
          window.__CCG_HEATMAP_PREVIEW = true;
          document.addEventListener('click', function(e) { e.preventDefault(); }, true);
          document.addEventListener('submit', function(e) { e.preventDefault(); }, true);
        </script>
        '''
        if "<head>" in html:
            html = html.replace("<head>", f"<head>{base_tag}{shield_script}", 1)
        elif "<HEAD>" in html:
            html = html.replace("<HEAD>", f"<HEAD>{base_tag}{shield_script}", 1)
        else:
            html = base_tag + shield_script + html

        return html.encode("utf-8"), "text/html; charset=utf-8"
    except Exception as e:
        err_html = f"""
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><title>Error al cargar página</title>
        <style>body{{font-family:sans-serif;padding:2rem;background:#f8fafc;color:#0f172a;text-align:center;}}</style>
        </head><body>
        <h2>No se pudo cargar la vista previa de {target_path}</h2>
        <p style="color:#ef4444;">{str(e)}</p>
        <p><a href="{url}" target="_blank">Abrir página directamente en nueva pestaña</a></p>
        </body></html>
        """
        return err_html.encode("utf-8"), "text/html; charset=utf-8"


# Inicializar DB al importar
try:
    init_db()
except Exception as e:
    print(f"[CCG-Analytics] Error inicializando DB: {e}")
