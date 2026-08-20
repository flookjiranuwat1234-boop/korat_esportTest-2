// assets/js/main.js
// ลูกเล่นภาพเคลื่อนไหวที่ใช้ร่วมกันทุกหน้า (ไฟล์นี้ถูกเรียกจาก includes/public_nav.php และ admin_nav.php
// เลยทำงานอัตโนมัติทุกหน้าโดยไม่ต้องแก้ไฟล์ PHP แต่ละหน้าเพิ่ม)

document.addEventListener('DOMContentLoaded', function () {
    initScrollReveal();
    initCountUp();
    initHeroParticles();
    initNavScrollShadow();
});

// 1) การ์ด/หัวข้อ/ตาราง fade-in ตอน scroll เข้ามาเห็น
function initScrollReveal() {
    var targets = document.querySelectorAll('.card, .stat-card, h2, .public-table, .admin-table, .bracket-round');
    if (!('IntersectionObserver' in window) || targets.length === 0) {
        targets.forEach(function (el) { el.classList.add('reveal-visible'); });
        return;
    }

    targets.forEach(function (el) { el.classList.add('reveal-pending'); });

    var observer = new IntersectionObserver(function (entries, obs) {
        entries.forEach(function (entry, i) {
            if (entry.isIntersecting) {
                // หน่วงเวลาเล็กน้อยไล่ทีละตัว ให้ดูเป็นจังหวะแทนที่จะโผล่มาพร้อมกันหมด
                setTimeout(function () {
                    entry.target.classList.add('reveal-visible');
                    entry.target.classList.remove('reveal-pending');
                }, (i % 6) * 70);
                obs.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

    targets.forEach(function (el) { observer.observe(el); });
}

// 2) ตัวเลขสถิตินับขึ้นแบบ animate
// ใช้กับ element ที่มี attribute data-countup="ตัวเลขปลายทาง" เช่น <h3 data-countup="94">94</h3>
function initCountUp() {
    var counters = document.querySelectorAll('[data-countup]');
    if (counters.length === 0) return;

    function animateCounter(el) {
        var target = parseInt(el.getAttribute('data-countup'), 10);
        if (isNaN(target)) return;
        var duration = 900;
        var startTime = null;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            // ease-out ให้ช่วงท้ายนับช้าลง ดูนุ่มนวลกว่าการนับเร็วเท่ากันตลอด
            var eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.floor(eased * target).toLocaleString('th-TH');
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = target.toLocaleString('th-TH');
            }
        }
        requestAnimationFrame(step);
    }

    if (!('IntersectionObserver' in window)) {
        counters.forEach(animateCounter);
        return;
    }

    var observer = new IntersectionObserver(function (entries, obs) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                animateCounter(entry.target);
                obs.unobserve(entry.target);
            }
        });
    }, { threshold: 0.4 });

    counters.forEach(function (el) { observer.observe(el); });
}

// 3) อนุภาคเพชรลอยในหน้า Hero (ล้อกับลายเพชรในโลโก้)
function initHeroParticles() {
    var hero = document.querySelector('.hero');
    if (!hero) return;

    var layer = document.createElement('div');
    layer.className = 'hero-particles';
    hero.appendChild(layer);

    var count = window.innerWidth < 640 ? 8 : 16;
    for (var i = 0; i < count; i++) {
        var p = document.createElement('span');
        p.className = 'hero-particle';
        p.style.left = Math.random() * 100 + '%';
        p.style.animationDelay = (Math.random() * 8) + 's';
        p.style.animationDuration = (7 + Math.random() * 6) + 's';
        var size = 6 + Math.random() * 10;
        p.style.width = size + 'px';
        p.style.height = size + 'px';
        layer.appendChild(p);
    }
}

// 4) เมนูด้านบนมีเงาเข้มขึ้นตอน scroll ลง
function initNavScrollShadow() {
    var nav = document.querySelector('.public-nav, .admin-nav');
    if (!nav) return;

    function updateShadow() {
        if (window.scrollY > 8) {
            nav.classList.add('nav-scrolled');
        } else {
            nav.classList.remove('nav-scrolled');
        }
    }
    updateShadow();
    window.addEventListener('scroll', updateShadow, { passive: true });
}
