// Shared mobile navigation for public pages with a Tailwind header.
document.addEventListener('DOMContentLoaded', function () {
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
            display: flex; position: absolute; top: .5rem; right: .75rem; z-index: 3;
            width: 2.5rem; height: 2.5rem; align-items: center; justify-content: center;
            border: 1px solid rgba(255,255,255,.2); border-radius: .5rem;
            background: rgba(18,19,24,.65); color: #fff; font-size: 1rem;
        }
        .shared-mobile-menu {
            display: none; position: absolute; top: 3.75rem; left: .75rem; right: .75rem;
            z-index: 4; flex-direction: column; gap: .25rem; padding: .5rem;
            border: 1px solid rgba(255,255,255,.15); border-radius: .75rem;
            background: rgba(18,19,24,.96); box-shadow: 0 1rem 2rem rgba(0,0,0,.35);
            backdrop-filter: blur(12px);
        }
        .shared-mobile-menu.is-open { display: flex; }
        .shared-mobile-menu-link {
            display: block; padding: .75rem 1rem; border-radius: .5rem;
            color: #e5e7eb !important; font-size: .875rem !important; font-weight: 600;
        }
        .shared-mobile-menu-link:hover, .shared-mobile-menu-link:focus-visible {
            background: rgba(255,85,0,.2); color: #ff7733 !important;
        }
    `;
    document.head.appendChild(styles);

    document.querySelectorAll('header').forEach(function (header, index) {
        var desktopNav = header.querySelector('nav.hidden.md\\:flex');
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

        Array.prototype.forEach.call(desktopNav.querySelectorAll('a'), function (link) {
            var item = link.cloneNode(true);
            item.className = 'shared-mobile-menu-link';
            mobileMenu.appendChild(item);
        });

        var userLinks = header.querySelectorAll('.max-w-7xl > div > div:last-child a');
        Array.prototype.forEach.call(userLinks, function (link) {
            var item = link.cloneNode(true);
            item.className = 'shared-mobile-menu-link';
            mobileMenu.appendChild(item);
        });

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
    });
});
