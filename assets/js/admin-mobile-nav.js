document.addEventListener('DOMContentLoaded', function () {
    if (window.innerWidth >= 1024) return;

    var sidebar = document.querySelector('aside');
    if (document.querySelector('.admin-mobile-navbar')) return;

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
        .admin-mobile-navbar.is-open {
            background: transparent; box-shadow: none; pointer-events: none;
        }
        .admin-mobile-navbar.is-open .admin-mobile-navbar-brand {
            visibility: hidden;
        }
        .admin-mobile-navbar.is-open .admin-mobile-menu-toggle {
            pointer-events: auto; position: fixed; top: .75rem; right: .75rem;
            width: 3rem; height: 3rem; border-radius: .75rem;
            background: rgba(15,23,42,.72); font-size: 1.25rem;
        }
        .admin-mobile-overlay {
            position: fixed; inset: 0; z-index: 75; background: rgba(2,6,23,.58);
            opacity: 0; pointer-events: none; transition: opacity .2s ease;
        }
        .admin-mobile-overlay.is-open { opacity: 1; pointer-events: auto; }
        body > aside, aside {
            position: fixed !important; top: 4.5rem !important; bottom: auto !important;
            left: 1rem !important; right: 1rem !important; z-index: 80 !important;
            width: auto !important; max-height: calc(100vh - 5.25rem) !important;
            border: 1px solid rgba(255,255,255,.14); border-radius: 1rem;
            overflow: hidden auto !important; background: #121318 !important;
            transform: translateY(-.75rem) !important; opacity: 0 !important;
            pointer-events: none !important;
            transition: transform .2s ease, opacity .2s ease !important;
        }
        aside.admin-mobile-open {
            transform: translateY(0) !important; opacity: 1 !important;
            pointer-events: auto !important;
        }
        aside > div:first-child,
        aside > div:last-child {
            display: none !important;
        }
        aside nav {
            padding: 1rem .75rem !important; overflow: visible !important;
        }
        aside nav .admin-mobile-logout {
            color: #fda4af;
            margin-top: .75rem;
            border-top: 1px solid rgba(255,255,255,.1);
            border-radius: 0;
            padding-top: 1rem !important;
        }
        aside nav .admin-mobile-logout:hover {
            color: #fff;
            background: rgba(244,63,94,.18);
        }
        aside nav a {
            min-height: 3.25rem; border-radius: .65rem; margin: .15rem 0;
            padding: .75rem 1rem !important; font-size: .95rem;
        }
        aside nav a:hover, aside nav a.active {
            background: rgba(255,255,255,.08);
        }
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

    if (!sidebar) {
        var fallbackToggle = navbar.querySelector('.admin-mobile-menu-toggle');
        fallbackToggle.outerHTML = '<a class="admin-mobile-menu-toggle" href="manage-members.php" aria-label="กลับหน้าสมาชิก"><i class="fa-solid fa-arrow-left"></i></a>';
        document.body.style.paddingTop = '3.75rem';
        return;
    }

    var overlay = document.createElement('div');
    overlay.className = 'admin-mobile-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    document.body.appendChild(overlay);

    var content = sidebar.nextElementSibling;
    if (content) content.classList.add('admin-mobile-content');

    var sidebarLogout = sidebar.querySelector('a[href*="logout"]');
    var mobileNav = sidebar.querySelector('nav');
    if (sidebarLogout && mobileNav && !mobileNav.querySelector('.admin-mobile-logout')) {
        var mobileLogout = sidebarLogout.cloneNode(true);
        mobileLogout.className = 'admin-mobile-logout flex items-center gap-3 px-4 py-3 text-rose-300';
        mobileLogout.innerHTML = '<i class="fa-solid fa-right-from-bracket w-5 text-center"></i><span>ออกจากระบบ</span>';
        mobileNav.appendChild(mobileLogout);
    }

    var toggle = navbar.querySelector('.admin-mobile-menu-toggle');
    function closeMenu() {
        sidebar.classList.remove('admin-mobile-open');
        navbar.classList.remove('is-open');
        overlay.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'เปิดเมนู');
        toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
    }
    function openMenu() {
        sidebar.classList.add('admin-mobile-open');
        navbar.classList.add('is-open');
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
