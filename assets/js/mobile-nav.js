// Shared mobile navigation for public pages with a Tailwind header.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.auth-mobile-nav').forEach(function (container) {
        var toggle = container.querySelector('.auth-mobile-menu-toggle');
        var menu = container.querySelector('.auth-mobile-menu');
        if (!toggle || !menu) return;
        toggle.addEventListener('click', function () {
            var isOpen = menu.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', String(isOpen));
            toggle.setAttribute('aria-label', isOpen ? 'ปิดเมนู' : 'เปิดเมนู');
            toggle.innerHTML = isOpen
                ? '<i class="fa-solid fa-xmark"></i>'
                : '<i class="fa-solid fa-bars"></i>';
        });
        menu.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                menu.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', 'เปิดเมนู');
                toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
            });
        });
    });

    var legacyPublicNav = document.querySelector('nav.public-nav');
    if (legacyPublicNav && window.innerWidth < 768 && !document.querySelector('.public-nav-mobile-toggle')) {
        legacyPublicNav.classList.add('public-nav-mobile');
        var legacyToggle = document.createElement('button');
        legacyToggle.type = 'button';
        legacyToggle.className = 'public-nav-mobile-toggle';
        legacyToggle.setAttribute('aria-expanded', 'false');
        legacyToggle.setAttribute('aria-label', 'เปิดเมนู');
        legacyToggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
        document.body.appendChild(legacyToggle);
        legacyToggle.addEventListener('click', function () {
            var isOpen = legacyPublicNav.classList.toggle('is-open');
            legacyToggle.setAttribute('aria-expanded', String(isOpen));
            legacyToggle.setAttribute('aria-label', isOpen ? 'ปิดเมนู' : 'เปิดเมนู');
            legacyToggle.innerHTML = isOpen
                ? '<i class="fa-solid fa-xmark"></i>'
                : '<i class="fa-solid fa-bars"></i>';
        });
        legacyPublicNav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                legacyPublicNav.classList.remove('is-open');
                legacyToggle.setAttribute('aria-expanded', 'false');
                legacyToggle.setAttribute('aria-label', 'เปิดเมนู');
                legacyToggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
            });
        });
    }

    if (window.innerWidth >= 768) return;

    var styles = document.createElement('style');
    styles.textContent = `
        .shared-mobile-header {
            position: relative !important;
            min-height: 3.5rem;
            background: transparent !important;
            border-bottom: 0 !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }
        .shared-mobile-header .max-w-7xl > div {
            height: 3.5rem !important;
            min-height: 3.5rem !important;
        }
        .shared-mobile-header .max-w-7xl > div > a:first-child,
        .shared-mobile-header .max-w-7xl > div > div:last-child {
            display: none !important;
        }
        .shared-mobile-header .shared-desktop-nav { display: none !important; }
        .shared-mobile-menu-toggle {
            display: flex; position: absolute; top: .75rem; right: .75rem; z-index: 6;
            width: 3rem; height: 3rem; align-items: center; justify-content: center;
            border: 1px solid rgba(255,255,255,.25); border-radius: .75rem;
            background: rgba(18,19,24,.72); color: #fff; font-size: 1.15rem;
        }
        .shared-mobile-menu {
            display: none; position: absolute; top: 4.5rem; left: 1rem; right: 1rem;
            z-index: 5; flex-direction: column; gap: .35rem; padding: .85rem .75rem;
            border: 1px solid rgba(255,255,255,.18); border-radius: 1rem;
            background: rgba(18,19,24,.97); box-shadow: 0 1rem 2rem rgba(0,0,0,.45);
            backdrop-filter: blur(12px);
        }
        .shared-mobile-menu.is-open { display: flex; }
        .shared-mobile-menu-link {
            display: flex; align-items: center; min-height: 3.25rem;
            padding: .75rem 1rem; border-radius: .65rem;
            color: #e5e7eb !important; font-size: .95rem !important; font-weight: 700;
        }
        .shared-mobile-menu-link:hover, .shared-mobile-menu-link:focus-visible {
            background: rgba(255,85,0,.2); color: #ff7733 !important;
        }
        .shared-mobile-menu-link i { width: 1.25rem; margin-right: .65rem; text-align: center; }
        .shared-mobile-menu-link.shared-mobile-admin-link { color: #ff5500 !important; }
        .shared-mobile-menu-link.shared-mobile-logout-link { color: #fda4af !important; }
    `;
    document.head.appendChild(styles);

    document.querySelectorAll('header').forEach(function (header, index) {
        var desktopNav = header.querySelector('nav.hidden.md\\:flex') || header.querySelector('nav:not(.public-nav)');
        if (!desktopNav || header.querySelector('.shared-mobile-menu-toggle')) return;

        var menuId = 'shared-mobile-menu-' + index;
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'shared-mobile-menu-toggle';
        toggle.setAttribute('aria-controls', menuId);
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'เปิดเมนู');
        toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';

        var mobileMenu = document.createElement('nav');
        mobileMenu.id = menuId;
        mobileMenu.className = 'shared-mobile-menu';
        mobileMenu.setAttribute('aria-label', 'เมนูหลัก');
        var isAdminPage = window.location.pathname.indexOf('/admin/') !== -1
            || document.body.getAttribute('data-admin-user') === '1';
        var pageHasAdminLink = Array.prototype.some.call(header.querySelectorAll('a[href]'), function (link) {
            var href = link.getAttribute('href') || '';
            return /(?:^|\/)admin\//.test(href);
        });

        Array.prototype.forEach.call(desktopNav.querySelectorAll('a'), function (link) {
            var item = link.cloneNode(true);
            item.className = 'shared-mobile-menu-link';
            var href = link.getAttribute('href') || '';
            if (!item.querySelector('i')) {
                var iconClass = href.indexOf('index.php') !== -1
                    ? 'fa-house'
                    : href.indexOf('tournaments.php') !== -1
                        ? 'fa-trophy'
                        : href.indexOf('ranking.php') !== -1
                            ? 'fa-ranking-star'
                            : href.indexOf('news.php') !== -1
                                ? 'fa-newspaper'
                                : href.indexOf('gallery.php') !== -1
                                    ? 'fa-images'
                                    : '';
                if (iconClass) {
                    item.innerHTML = '<i class="fa-solid ' + iconClass + '"></i> ' + item.textContent.trim();
                }
            }
            mobileMenu.appendChild(item);
        });

        var userLinks = Array.prototype.filter.call(header.querySelectorAll('a[href]'), function (link) {
            var href = link.getAttribute('href') || '';
            if (link.closest('nav') === desktopNav) return false;
            if ((isAdminPage || pageHasAdminLink) && /(?:^|\/)profile\.php/.test(href)) return false;
            return href.indexOf('profile.php') !== -1
                || href.indexOf('logout') !== -1
                || /(?:^|\/)(?:login|register)\.php/.test(href)
                || href.indexOf('../admin/') !== -1
                || href.indexOf('/admin/') !== -1;
        });
        userLinks.forEach(function (link) {
            var item = link.cloneNode(true);
            item.className = 'shared-mobile-menu-link';
            var mobileLabel = link.getAttribute('data-mobile-label');
            var href = link.getAttribute('href') || '';
            if (!mobileLabel && (href.indexOf('../admin/') !== -1 || href.indexOf('/admin/') !== -1)) {
                mobileLabel = 'ระบบแอดมิน';
            } else if (!mobileLabel && href.indexOf('logout') !== -1) {
                mobileLabel = 'ออกจากระบบ';
            } else if (!mobileLabel && href.indexOf('profile.php') !== -1 && !isAdminPage && !pageHasAdminLink) {
                mobileLabel = 'โปรไฟล์ของฉัน';
            } else if (!mobileLabel && /(?:^|\/)login\.php/.test(href)) {
                mobileLabel = 'เข้าสู่ระบบ';
            } else if (!mobileLabel && /(?:^|\/)register\.php/.test(href)) {
                mobileLabel = 'สมัครสมาชิก';
            }
            if (mobileLabel) {
                if (mobileLabel === 'ระบบแอดมิน') {
                    item.classList.add('shared-mobile-admin-link');
                } else if (mobileLabel === 'ออกจากระบบ') {
                    item.classList.add('shared-mobile-logout-link');
                }
                if (mobileLabel === 'เข้าสู่ระบบ') {
                    item.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> ' + mobileLabel;
                } else if (mobileLabel === 'สมัครสมาชิก') {
                    item.innerHTML = '<i class="fa-solid fa-user-plus"></i> ' + mobileLabel;
                } else {
                    item.appendChild(document.createTextNode(' ' + mobileLabel));
                }
            }
            mobileMenu.appendChild(item);
        });

        var hasProfileLink = userLinks.some(function (link) {
            return (link.getAttribute('href') || '').indexOf('profile.php') !== -1;
        });
        var hasLogoutLink = userLinks.some(function (link) {
            return (link.getAttribute('href') || '').indexOf('logout') !== -1;
        });
        var hasAdminLink = userLinks.some(function (link) {
            var href = link.getAttribute('href') || '';
            return /(?:^|\/)admin\//.test(href);
        });
        if (!hasProfileLink && hasLogoutLink && !hasAdminLink && !isAdminPage) {
            var profileItem = document.createElement('a');
            profileItem.href = 'profile.php';
            profileItem.className = 'shared-mobile-menu-link';
            profileItem.innerHTML = '<i class="fa-solid fa-user"></i> โปรไฟล์ของฉัน';
            var logoutItem = mobileMenu.querySelector('.shared-mobile-logout-link');
            if (logoutItem) {
                mobileMenu.insertBefore(profileItem, logoutItem);
            } else {
                mobileMenu.appendChild(profileItem);
            }

        }

        if (isAdminPage || pageHasAdminLink) {
            mobileMenu.querySelectorAll('a[href*="profile.php"]').forEach(function (link) {
                link.remove();
            });
        }

        desktopNav.classList.add('shared-desktop-nav');
        header.classList.add('shared-mobile-header');
        header.appendChild(toggle);
        header.appendChild(mobileMenu);

        toggle.addEventListener('click', function () {
            var open = mobileMenu.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', String(open));
            toggle.setAttribute('aria-label', open ? 'ปิดเมนู' : 'เปิดเมนู');
            toggle.innerHTML = open
                ? '<i class="fa-solid fa-xmark"></i>'
                : '<i class="fa-solid fa-bars"></i>';
        });

        mobileMenu.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                mobileMenu.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', 'เปิดเมนู');
                toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
            });
        });
    });
});
