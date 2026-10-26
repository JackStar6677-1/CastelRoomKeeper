(function () {
    'use strict';

    var sections = [
        {
            label: 'Nuestro Colegio',
            slug: 'nuestro-colegio',
            links: [
                ['Galería', '/galeria/'],
                ['Infraestructura', '/infraestructura/'],
                ['Misión y visión', '/mision-y-vision/'],
                ['Proyecto Educativo', '/proyecto-educativo/'],
                ['Espacios Creativos', '/espacios-creativos/'],
                ['Noticias', '/noticias/'],
                ['Secretaría y Asistentes', '/secretaria-y-asistentes-de-la-educacion/'],
                ['Equipo Directivo', '/equipo-directivo/'],
                ['Equipo Docente', '/equipo-docente/']
            ]
        },
        {
            label: 'Convivencia Escolar',
            slug: 'convivencia-escolar',
            links: [
                ['Reglamento Interno', '/reglamento-interno/'],
                ['Manual de Evaluación', '/manual-de-evaluacion/']
            ]
        },
        {
            label: 'Académico',
            slug: 'academico',
            links: [
                ['Lista de útiles 2026', '/lista-de-utiles-2026/'],
                ['Plan Lector 2026', '/plan-lector-2026/']
            ]
        },
        {
            label: 'Comunidad',
            slug: 'comunidad',
            links: [
                ['Perfil del Apoderado', '/perfil-del-apoderado/'],
                ['Perfil del Estudiante', '/perfil-del-estudiante/'],
                ['Contacto', '/contacto/'],
                ['Convenios', '/convenios/'],
                ['Acceso estudiantes', '/acceso-estudiantes/'],
                ['Acceso docentes', '/acceso-docentes/'],
                ['Sofia School', 'https://www.gptsofia.com/school', true],
                ['Webmail', 'https://webmail.colegiocastelgandolfo.cl/', true]
            ]
        },
        {
            label: 'Admisión',
            slug: 'admision',
            links: [
                ['Admisión 2027', '/admision2027/'],
                ['Admitidos 2027', '/listado-de-admitidos/'],
                ['Becas', '/becas/']
            ]
        }
    ];

    /** Convierte una ruta en una forma estable para comparar el enlace activo. */
    function normalizePath(pathname) {
        var clean = String(pathname || '/').replace(/\/index\.html$/, '/');
        return clean.length > 1 ? clean.replace(/\/$/, '') : '/';
    }

    /** Construye un enlace seguro y marca la página actual para lectores de pantalla. */
    function createLink(definition, currentPath) {
        var link = document.createElement('a');
        var external = Boolean(definition[2]);
        link.textContent = definition[0];
        link.href = definition[1];

        if (external) {
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
        } else if (normalizePath(definition[1]) === currentPath) {
            link.setAttribute('aria-current', 'page');
        }

        return link;
    }

    /** Genera el menú semántico estandarizado en todas las páginas públicas del colegio. */
    function buildNavigation(nav) {
        var list = document.createElement('ul');
        var currentPath = normalizePath(window.location.pathname);

        sections.forEach(function (section) {
            var item = document.createElement('li');
            var button = document.createElement('button');
            var submenu = document.createElement('ul');
            var submenuId = 'nav-submenu-' + section.slug;

            item.className = 'dropdown nav-item-' + section.slug;
            button.type = 'button';
            button.className = 'nav-section-toggle';
            button.textContent = section.label;
            button.setAttribute('aria-expanded', 'false');
            button.setAttribute('aria-controls', submenuId);
            submenu.className = 'dropdown-menu';
            submenu.id = submenuId;

            section.links.forEach(function (definition) {
                var submenuItem = document.createElement('li');
                submenuItem.appendChild(createLink(definition, currentPath));
                submenu.appendChild(submenuItem);
            });

            item.appendChild(button);
            item.appendChild(submenu);
            list.appendChild(item);
        });

        nav.replaceChildren(list);
    }

    /** Cierra todos los desplegables salvo el indicado. */
    function closeDropdowns(header, except) {
        header.querySelectorAll('.dropdown.is-open').forEach(function (item) {
            if (item === except) return;
            item.classList.remove('is-open');
            var button = item.querySelector('.nav-section-toggle');
            if (button) button.setAttribute('aria-expanded', 'false');
        });
    }

    /** Sincroniza el control de menú móvil con su estado visual. */
    function syncMobileState(header, checkbox, control) {
        var isOpen = Boolean(checkbox && checkbox.checked);
        header.classList.toggle('is-menu-open', isOpen);
        if (control) control.setAttribute('aria-expanded', String(isOpen));
        if (!isOpen) closeDropdowns(header);
    }

    /** Instala controles accesibles de teclado, clic y cierre exterior. */
    function installInteractions(header, nav) {
        var checkbox = header.querySelector('.nav-toggle');
        var control = header.querySelector('.nav-toggle-button');

        nav.querySelectorAll('.nav-section-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                var item = button.closest('.dropdown');
                var willOpen = !item.classList.contains('is-open');
                closeDropdowns(header, item);
                item.classList.toggle('is-open', willOpen);
                button.setAttribute('aria-expanded', String(willOpen));
            });
        });

        if (control) {
            control.setAttribute('role', 'button');
            control.setAttribute('tabindex', '0');
            control.setAttribute('aria-controls', 'site-navigation');
            control.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                if (checkbox) checkbox.checked = !checkbox.checked;
                syncMobileState(header, checkbox, control);
            });
        }

        if (checkbox) {
            checkbox.addEventListener('change', function () {
                syncMobileState(header, checkbox, control);
            });
        }

        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (checkbox) checkbox.checked = false;
                syncMobileState(header, checkbox, control);
            });
        });

        document.addEventListener('click', function (event) {
            if (!header.contains(event.target)) closeDropdowns(header);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            closeDropdowns(header);
            if (checkbox) checkbox.checked = false;
            syncMobileState(header, checkbox, control);
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth <= 900 || !checkbox) return;
            checkbox.checked = false;
            syncMobileState(header, checkbox, control);
        });

        syncMobileState(header, checkbox, control);
    }

    /** Aplica la estandarización una sola vez y conserva la estructura de cada página. */
    function standardizeHeader() {
        try {
            var header = document.querySelector('.site-header');
            var bar = header && header.querySelector('.site-header__bar');
            var nav = header && header.querySelector('.site-nav');
            if (!header || !bar || !nav || header.dataset.standardizedHeader === 'true') return;

            nav.id = 'site-navigation';
            nav.setAttribute('aria-label', 'Navegación principal');
            buildNavigation(nav);
            installInteractions(header, nav);
            header.dataset.standardizedHeader = 'true';
        } catch (error) {
            console.error('[Castel Header] No fue posible estandarizar el encabezado.', error);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', standardizeHeader);
    } else {
        standardizeHeader();
    }
}());

/* --- Castelgandolfo Universal Analytics & Heatmap Engine (CCG Fisico) --- */
(function () {
  'use strict';
  try {
    if (!window.__CCG_ANALYTICS_LOADED && !document.getElementById('ccg-analytics-tag')) {
      var s = document.createElement('script');
      s.id = 'ccg-analytics-tag';
      s.src = '/assets/castel-analytics.js';
      s.async = true;
      document.head.appendChild(s);
    }
  } catch (_) {}
})();
