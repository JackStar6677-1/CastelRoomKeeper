# 🏛️ Manual de Arquitectura de la Suite Administrativa CCG
> **Ecosistema Digital Colegio Castel Gandolfo**  
> **Servidor Web Principal:** cPanel Dedicated Hosting (\`colegiocastelgandolfo.cl\` / \`186.64.119.155\`)  
> **Servidor Local On-Premise:** \`ccg-fisico\` (\`192.168.0.120\` / \`100.110.230.7\`)  
> **Última Actualización:** Octubre 2026

---

## 🧭 1. Visión General del Ecosistema Híbrido

La infraestructura digital del colegio opera bajo un modelo **híbrido simétrico** compuesto por dos entornos completamente coordinados pero técnicamente aislados:

\`\`\`
                              [ INTERNET PÚBLICO ]
                                      │
              ┌───────────────────────┴───────────────────────┐
              ▼                                               ▼
   [ SERVIDOR DEDICADO CPANEL ]                     [ TAILSCALE FUNNEL RELAY ]
   colegiocastelgandolfo.cl                         ccg-fisico.tail0e08b5.ts.net
   IP: 186.64.119.155 (LiteSpeed/PHP)               (SSL Let's Encrypt / QUIC)
   ├── Web Oficial Colegio                                    │
   ├── Correos, Webmail, POP3/IMAP                            │
   └── Zona Administrativa (/admin/)                          │
       ├── hub.php (Portal Central)                           │
       ├── calendar.php (CastelRoomKeeper)                    │
       ├── documentos.php (Gestor Docs)                       │
       └── logs.php (Auditoría)                               │
                                                              ▼
                                                   [ COLEGIO LAN (192.168.0.x) ]
                                                   CCG Físico (192.168.0.120)
                                                   ├── DHCP L2 (118+ reservas Veyon)
                                                   ├── DNS Escolar (dnsmasq)
                                                   ├── CastelBoard (:8087)
                                                   └── EduDocente Studio (:8086)
\`\`\`

---

## 📂 2. Módulos que Corren en el Servidor Dedicado (\`/admin/\`)

Todos estos módulos se ejecutan directamente en el servidor web del cPanel con base de datos MySQL institucional:

1. **\`hub.php\` (CCG Hub):**
   - Centro neurálgico de acceso con autenticación unificada (\`auth.php\`).
   - Muestra las tarjetas de los sistemas activos, estado de red y accesos rápidos.
2. **\`calendar.php\` (CastelRoomKeeper):**
   - Motor de reservas de laboratorios y salas de computación.
   - Restricciones automáticas de bloques horarios, no-traslape y validación en tiempo real.
3. **\`documentos.php\` y \`documento.php\`:**
   - Repositorio institucional de reglamentos, circulares y actas del colegio.
4. **\`incidencias.php\`:**
   - Libro digital de incidencias técnicas en salas de computación.
5. **\`logs.php\`:**
   - Registro de auditoría cronológica de inicios de sesión y operaciones administrativas.
6. **\`calendar_alerts.php\`:**
   - Configuración de alertas y recordatorios automáticos por correo institucional (\`mail_config.php\`).

---

## ⚡ 3. Módulos que Corren en CCG Físico (\`ccg-fisico\`)

1. **CastelBoard (Portafolio & Aula):**
   - Motor en Python/SQLite en el puerto \`8087\`.
   - **Acceso Remoto Seguro (Tailscale Funnel):** \`https://ccg-fisico.tail0e08b5.ts.net/\`
   - **Acceso Interno Escolar:** \`http://castelboard.castelgandolfo\`
2. **EduDocente Studio (Generador Pedagógico):**
   - Motor en Python en el puerto \`8086\`.
   - **Acceso Remoto Seguro (Tailscale Funnel):** \`https://ccg-fisico.tail0e08b5.ts.net:8443/\`
   - **Acceso Interno Escolar:** \`http://estudio.castelgandolfo\`

---

## 🔐 4. Parámetros de Red y Seguridad

- **DNS Escolar Local:** El servidor físico resuelve internamente los dominios \`.castelgandolfo\` sin consumir internet escolar.
- **Túnel Institucional:** Gestionado bajo la cuenta oficial \`administracion.ti@colegiocastelgandolfo.cl\` en Tailscale.
- **Compatibilidad Universal:** Tanto profesores como alumnos pueden acceder sin necesidad de instalar VPNs o clientes adicionales.
EOF

cp /home/jack/mnt/castel/admin/README_ARQUITECTURA.md /home/jack/Documentos/Desarrollo/Repositorios/personal/CastelRoomKeeper/admin/README_ARQUITECTURA.md
echo \"Documentación creada en servidor dedicado y repo local\"
