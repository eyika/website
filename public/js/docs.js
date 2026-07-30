/* Atom Docs — interactions */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        initSidebarAccordion();
        initActiveState();
        initToc();            // before heading anchors, so TOC text stays clean
        initHeadingAnchors();
        initCopyButtons();
        initMobileNav();
    });

    /* ---- Theme toggle (data-theme on <html>, persisted) ---------------- */
    function initTheme() {
        var toggle = document.getElementById('theme-toggle');
        if (!toggle) return;
        toggle.addEventListener('click', function () {
            var root = document.documentElement;
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) {}
        });
    }

    /* ---- Sidebar accordion: single group open at a time ---------------- */
    function initSidebarAccordion() {
        var toggles = document.querySelectorAll('.nav-group-toggle');
        toggles.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = btn.closest('.nav-group');
                var willOpen = !group.classList.contains('open');

                // Accordion: collapse every other group first.
                document.querySelectorAll('.nav-group.open').forEach(function (g) {
                    if (g !== group) {
                        g.classList.remove('open');
                        var t = g.querySelector('.nav-group-toggle');
                        if (t) t.setAttribute('aria-expanded', 'false');
                    }
                });

                group.classList.toggle('open', willOpen);
                btn.setAttribute('aria-expanded', String(willOpen));
            });
        });
    }

    /* ---- Highlight the current page + open its group ------------------- */
    function initActiveState() {
        var here = normalize(window.location.pathname);
        var links = document.querySelectorAll('#sidebar .nav-link');
        var active = null;

        links.forEach(function (link) {
            if (normalize(link.getAttribute('href')) === here) active = link;
        });
        if (!active) return;

        active.classList.add('active');
        active.setAttribute('aria-current', 'page');

        var group = active.closest('.nav-group');
        if (group) {
            group.classList.add('open');
            var t = group.querySelector('.nav-group-toggle');
            if (t) t.setAttribute('aria-expanded', 'true');
        }

        // Keep the active item in view within the sidebar (not the page).
        var sidebar = document.getElementById('sidebar');
        if (sidebar) {
            var offset = active.offsetTop - sidebar.clientHeight / 2;
            sidebar.scrollTop = Math.max(0, offset);
        }
    }

    function normalize(path) {
        if (!path) return '';
        return path.replace(/\/+$/, '') || '/';
    }

    /* ---- Copy buttons on code blocks ---------------------------------- */
    function initCopyButtons() {
        document.querySelectorAll('.docs-content pre').forEach(function (pre) {
            var btn = document.createElement('button');
            btn.className = 'copy-btn';
            btn.type = 'button';
            btn.textContent = 'Copy';
            btn.setAttribute('aria-label', 'Copy code to clipboard');

            btn.addEventListener('click', function () {
                var code = pre.querySelector('code');
                var text = code ? code.innerText : pre.innerText;
                copy(text).then(function () {
                    btn.textContent = 'Copied!';
                    btn.classList.add('copied');
                    setTimeout(function () {
                        btn.textContent = 'Copy';
                        btn.classList.remove('copied');
                    }, 1800);
                });
            });

            pre.appendChild(btn);
        });
    }

    function copy(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve) {
            var ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta); resolve();
        });
    }

    /* ---- "On this page" table of contents + scroll-spy ---------------- */
    function initToc() {
        var toc = document.querySelector('.toc');
        var tocNav = document.querySelector('.toc-nav');
        var content = document.querySelector('.docs-content');
        var scroller = document.querySelector('.docs-main');
        if (!toc || !tocNav || !content) return;

        var headings = Array.prototype.slice.call(content.querySelectorAll('h2[id], h3[id]'));
        if (headings.length < 2) { toc.style.display = 'none'; return; }

        var ul = document.createElement('ul');
        var linkById = {};
        headings.forEach(function (h) {
            var a = document.createElement('a');
            a.className = 'toc-link' + (h.tagName === 'H3' ? ' lvl-3' : '');
            a.href = '#' + h.id;
            a.textContent = h.textContent;
            var li = document.createElement('li');
            li.appendChild(a);
            ul.appendChild(li);
            linkById[h.id] = a;
        });
        tocNav.appendChild(ul);

        var currentId = null;
        function setActive(id) {
            if (id === currentId || !linkById[id]) return;
            if (currentId && linkById[currentId]) linkById[currentId].classList.remove('active');
            linkById[id].classList.add('active');
            currentId = id;
        }

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (e.isIntersecting) setActive(e.target.id);
                });
            }, { root: scroller, rootMargin: '0px 0px -72% 0px', threshold: 0 });
            headings.forEach(function (h) { observer.observe(h); });
        }
        setActive(headings[0].id);
    }

    /* ---- Clickable anchor links on headings --------------------------- */
    function initHeadingAnchors() {
        document.querySelectorAll('.docs-content h1[id], .docs-content h2[id], .docs-content h3[id], .docs-content h4[id]')
            .forEach(function (h) {
                var a = document.createElement('a');
                a.className = 'heading-anchor';
                a.href = '#' + h.id;
                a.setAttribute('aria-label', 'Link to this section');
                a.textContent = '#';
                h.insertBefore(a, h.firstChild);
            });
    }

    /* ---- Mobile drawer ------------------------------------------------ */
    function initMobileNav() {
        var toggle = document.querySelector('.menu-toggle');
        var sidebar = document.getElementById('sidebar');
        var overlay = document.querySelector('.sidebar-overlay');
        if (!toggle || !sidebar || !overlay) return;

        function open() {
            sidebar.classList.add('open');
            overlay.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
        }
        function close() {
            sidebar.classList.remove('open');
            overlay.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function () {
            sidebar.classList.contains('open') ? close() : open();
        });
        overlay.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
        // Navigating (a real link, not a group toggle) closes the drawer.
        sidebar.addEventListener('click', function (e) {
            if (e.target.closest('.nav-link')) close();
        });
    }
})();
