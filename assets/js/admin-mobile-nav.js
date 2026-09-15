document.addEventListener('DOMContentLoaded', function () {
    if (window.innerWidth >= 1024) return;

    var sidebar = document.querySelector('aside');
    if (!sidebar || document.querySelector('.admin-mobile-navbar')) return;

    var styles = document.createElement('style');
    styles.textContent = `
        body { overflow-x: hidden; }
        .admin-mobile-navbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 70;
            display: flex; align-items: center; justify-content: space-between;
            height: 3.75rem; padding: .5rem .875rem;
            background: #0f172a; color: #fff; box-shadow: 0 2px 10px rgba(15,23,42,.2);
        }
        .admin-mobile-navbar-brand { display: flex; align-items: center; gap: .5rem; min-width: 0; }
        .admin-mobile-navbar-brand img { width: 2.25rem; height: 2.25rem; object-fit: contain; }
        .admin-mobile-navbar-brand span { font-size: .75rem; font-weight: 800; letter-spacing: .04em; white-space: nowrap; }
        .admin-mobile-menu-toggle {
            display: flex; align-items: center; justify-content: center;
            width: 2.5rem; height: 2.5rem; border: 1px solid rgba(255,255,255,.2);
            border-radius: .5rem; background: rgba(255,255,255,.08); color: #fff;
        }
        .admin-mobile-overlay {
            position: fixed; inset: 0; z-index: 75; background: rgba(2,6,23,.58);
            opacity: 0; pointer-events: none; transition: opacity .2s ease;
        }
        .admin-mobile-overlay.is-open { opacity: 1; pointer-events: auto; }
        body > aside, aside {
            position: fixed !important; top: 0 !important; bottom: 0 !important; left: 0 !important;
            z-index: 80 !important; width: min(19rem, 86vw) !important;
            transform: translateX(-105%) !important; transition: transform .2s ease !important;
        }
        aside.admin-mobile-open { transform: translateX(0) !important; }
        .admin-mobile-content { margin-left: 0 !important; padding-top: 3.75rem !important; }
        .admin-mobile-content > header { top: 3.75rem !important; }
        .admin-mobile-content > main { min-width: 0 !important; }
    `;
    document.head.appendChild(styles);

    var navbar = document.createElement('div');
    navbar.className = 'admin-mobile-navbar';
    navbar.innerHTML = `
        <a class="admin-mobile-navbar-brand" href="dashboard.php" aria-label="หน้าหลักแอดมิน">
            <img src="../assets/img/logo.png" alt="">
            <span>KORAT ESPORT <b style="color:#ff5500">ADMIN</b></span>
        </a>
        <button class="admin-mobile-menu-toggle" type="button" aria-label="เปิดเมนู" aria-expanded="false">
            <i class="fa-solid fa-bars"></i>
        </button>
    `;
    document.body.appendChild(navbar);

    var overlay = document.createElement('div');
    overlay.className = 'admin-mobile-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    document.body.appendChild(overlay);

    var content = sidebar.nextElementSibling;
    if (content) content.classList.add('admin-mobile-content');

    var toggle = navbar.querySelector('.admin-mobile-menu-toggle');
    function closeMenu() {
        sidebar.classList.remove('admin-mobile-open');
        overlay.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'เปิดเมนู');
        toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
    }
    function openMenu() {
        sidebar.classList.add('admin-mobile-open');
        overlay.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'ปิดเมนู');
        toggle.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    }

    toggle.addEventListener('click', function () {
        sidebar.classList.contains('admin-mobile-open') ? closeMenu() : openMenu();
    });
    overlay.addEventListener('click', closeMenu);
    sidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', closeMenu);
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1024) closeMenu();
    });
});
