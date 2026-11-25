/**
 * Domek Brenna - Strona internetowa
 * Skrypt główny (vanilla JS)
 *
 * Funkcje:
 * 1. Nawigacja - zmiana tła po przewinięciu
 * 2. Menu mobilne - hamburger, overlay, zamykanie
 * 3. Płynne przewijanie - z offsetem 70px
 * 4. Animacje przy przewijaniu - IntersectionObserver
 * 5. Parallax - subtelne przesunięcie tła
 * 6. Walidacja formularza - daty, pola wymagane, email
 * 7. Aktywny link nawigacji - podświetlanie wg pozycji
 * 8. Animacja liczb - odliczanie w cenach
 * 9. Pasek postępu - cienka linia u góry strony
 */

document.addEventListener('DOMContentLoaded', function () {

    // ============================================
    // ELEMENTY DOM
    // ============================================
    const nav = document.getElementById('nav');
    const hamburger = document.getElementById('hamburger');
    const mobileMenu = document.getElementById('mobileMenu');
    const scrollProgress = document.getElementById('scrollProgress');
    const bookingForm = document.getElementById('bookingForm');
    const formSuccess = document.getElementById('formSuccess');
    const formError = document.getElementById('formError');
    const checkInInput = document.getElementById('checkIn');
    const checkOutInput = document.getElementById('checkOut');
    const navLinks = document.querySelectorAll('.nav-link');
    const mobileMenuLinks = document.querySelectorAll('.mobile-menu-link, .mobile-menu-cta');
    const heroDots = document.querySelectorAll('.hero-dots .dot');

    // ============================================
    // 1. NAWIGACJA - zmiana tła po przewinięciu
    // ============================================
    function handleNavScroll() {
        if (!nav) return; // strona bez głównej nawigacji (np. kontakt.html)
        if (window.scrollY > 100) {
            nav.classList.add('nav-scrolled');
        } else {
            nav.classList.remove('nav-scrolled');
        }
    }

    window.addEventListener('scroll', handleNavScroll, { passive: true });
    handleNavScroll(); // Wywołanie na starcie

    // ============================================
    // 2. MENU MOBILNE
    // ============================================
    function openMobileMenu() {
        document.body.classList.add('menu-open');
        document.body.style.overflow = 'hidden';
    }

    function closeMobileMenu() {
        document.body.classList.remove('menu-open');
        document.body.style.overflow = '';
    }

    function toggleMobileMenu() {
        if (document.body.classList.contains('menu-open')) {
            closeMobileMenu();
        } else {
            openMobileMenu();
        }
    }

    if (hamburger) hamburger.addEventListener('click', toggleMobileMenu);

    // Zamknij po kliknięciu w link
    mobileMenuLinks.forEach(function (link) {
        link.addEventListener('click', closeMobileMenu);
    });

    // Zamknij po kliknięciu w tło overlay
    if (mobileMenu) mobileMenu.addEventListener('click', function (e) {
        if (e.target === mobileMenu) {
            closeMobileMenu();
        }
    });

    // Zamknij na Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('menu-open')) {
            closeMobileMenu();
        }
    });

    // ============================================
    // 3. PŁYNNE PRZEWIJANIE z offsetem
    // ============================================
    var NAV_OFFSET = 106; /* 36px pasek kontaktowy + 70px nawigacja */

    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            var targetId = this.getAttribute('href');
            if (targetId === '#') return;

            var targetEl = document.querySelector(targetId);
            if (!targetEl) return;

            e.preventDefault();

            var targetPosition = targetEl.getBoundingClientRect().top + window.pageYOffset - NAV_OFFSET;

            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        });
    });

    // ============================================
    // 4. ANIMACJE PRZY PRZEWIJANIU (IntersectionObserver)
    // ============================================
    var animatedElements = document.querySelectorAll('.animate-on-scroll');

    if ('IntersectionObserver' in window) {
        var scrollObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    // Raz widoczny - nie obserwujemy dalej
                    scrollObserver.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.15,
            rootMargin: '0px 0px -50px 0px'
        });

        animatedElements.forEach(function (el) {
            scrollObserver.observe(el);
        });
    } else {
        // Fallback - pokaż wszystko od razu
        animatedElements.forEach(function (el) {
            el.classList.add('visible');
        });
    }

    // ============================================
    // 5. PARALLAX - subtelne przesunięcie tła
    // ============================================
    var parallaxElements = document.querySelectorAll('.parallax .parallax-bg');

    function handleParallax() {
        parallaxElements.forEach(function (el) {
            var section = el.parentElement;
            var rect = section.getBoundingClientRect();
            var windowH = window.innerHeight;

            // Element jest widoczny na ekranie
            if (rect.top < windowH && rect.bottom > 0) {
                var scrolled = (windowH - rect.top) / (windowH + rect.height);
                var translateY = (scrolled - 0.5) * rect.height * 0.3;
                el.style.transform = 'translateY(' + translateY + 'px)';
            }
        });
    }

    window.addEventListener('scroll', handleParallax, { passive: true });
    handleParallax();

    // ============================================
    // 6. WALIDACJA FORMULARZA
    // ============================================

    // Ustaw minimalną datę zameldowania na dziś (tylko jeśli elementy istnieją)
    function setMinDates() {
        if (!checkInInput || !checkOutInput) return;
        var today = new Date();
        var todayStr = today.toISOString().split('T')[0];
        checkInInput.setAttribute('min', todayStr);

        checkInInput.addEventListener('change', function () {
            if (this.value) {
                var nextDay = new Date(this.value);
                nextDay.setDate(nextDay.getDate() + 1);
                var nextDayStr = nextDay.toISOString().split('T')[0];
                checkOutInput.setAttribute('min', nextDayStr);

                if (checkOutInput.value && checkOutInput.value <= this.value) {
                    checkOutInput.value = nextDayStr;
                }
            }
        });
    }

    setMinDates();

    // Walidacja email
    function isValidEmail(email) {
        var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    // Obsługa formularza - przekieruj do pełnego formularza z datami
    if (bookingForm) {
        bookingForm.addEventListener('submit', function (e) {
            e.preventDefault();

            // Czytaj ISO daty z data-iso (ustawiane przez handleDayClick)
            var checkIn  = checkInInput  ? (checkInInput.getAttribute('data-iso')  || checkInInput.value)  : '';
            var checkOut = checkOutInput ? (checkOutInput.getAttribute('data-iso') || checkOutInput.value) : '';
            var guestsEl = document.getElementById('guests');
            var guests   = guestsEl ? guestsEl.value : '';

            if (!checkIn || !checkOut) return; // przycisk jest disabled gdy brak dat - backup check

            var params = 'checkin=' + encodeURIComponent(checkIn) + '&checkout=' + encodeURIComponent(checkOut);
            if (guests) params += '&goscie=' + encodeURIComponent(guests);
            window.location.href = 'rezerwacje.html?' + params;
        });
    }

    // ============================================
    // 7. AKTYWNY LINK NAWIGACJI wg pozycji scrollu
    // ============================================
    var sections = document.querySelectorAll('section[id]');

    function highlightActiveNav() {
        var scrollPos = window.scrollY + NAV_OFFSET + 100;

        sections.forEach(function (section) {
            var sectionTop = section.offsetTop;
            var sectionHeight = section.offsetHeight;
            var sectionId = section.getAttribute('id');

            if (scrollPos >= sectionTop && scrollPos < sectionTop + sectionHeight) {
                // Podświetl link nawigacji
                navLinks.forEach(function (link) {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === '#' + sectionId) {
                        link.classList.add('active');
                    }
                });

                // Podświetl kropki hero
                heroDots.forEach(function (dot) {
                    dot.classList.remove('active');
                    if (dot.getAttribute('data-section') === sectionId) {
                        dot.classList.add('active');
                    }
                });
            }
        });
    }

    window.addEventListener('scroll', highlightActiveNav, { passive: true });
    highlightActiveNav();

    // ============================================
    // 8. ANIMACJA LICZB w cenach
    // ============================================
    var priceElements = document.querySelectorAll('.pricing-amount[data-target]');
    var priceAnimated = false;

    function animateNumbers() {
        if (priceAnimated) return;

        priceElements.forEach(function (el) {
            var rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight && rect.bottom > 0) {
                priceAnimated = true;
                startCountUp(el);
            }
        });
    }

    function startCountUp(el) {
        var target = parseInt(el.getAttribute('data-target'), 10);
        var duration = 1200; // ms
        var start = 0;
        var startTime = null;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);

            // Easing - ease-out
            var eased = 1 - Math.pow(1 - progress, 3);
            var current = Math.floor(eased * target);

            el.textContent = current;

            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = target;
            }
        }

        requestAnimationFrame(step);
    }

    // Uruchom animację liczb dla wszystkich elementów cenowych
    if ('IntersectionObserver' in window) {
        var priceObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    // Animuj wszystkie ceny naraz
                    priceElements.forEach(function (el) {
                        startCountUp(el);
                    });
                    priceObserver.disconnect();
                }
            });
        }, {
            threshold: 0.3
        });

        if (priceElements.length > 0) {
            priceObserver.observe(priceElements[0]);
        }
    } else {
        // Fallback - pokaż od razu
        priceElements.forEach(function (el) {
            el.textContent = el.getAttribute('data-target');
        });
    }

    // ============================================
    // 9. PASEK POSTĘPU PRZEWIJANIA
    // ============================================
    function updateScrollProgress() {
        if (!scrollProgress) return;
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        var docHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
        var scrollPercent = (scrollTop / docHeight) * 100;
        scrollProgress.style.width = scrollPercent + '%';
    }

    window.addEventListener('scroll', updateScrollProgress, { passive: true });
    updateScrollProgress();

    // ============================================
    // 10. KALENDARZ DOSTĘPNOŚCI
    // ============================================
    var calendarDays = document.getElementById('calendarDays');
    var calMonthLabel = document.getElementById('calMonthLabel');
    var calPrev = document.getElementById('calPrev');
    var calNext = document.getElementById('calNext');

    if (calendarDays && calMonthLabel) {
        var calCurrentDate = new Date();
        var calYear = calCurrentDate.getFullYear();
        var calMonth = calCurrentDate.getMonth();

        var bookedSet = {};

        // Pobierz zajęte daty z Booking.com (iCal, cache 1h)
        fetch('/availability.php')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                (data.blocked || []).forEach(function(d) { bookedSet[d] = true; });
                renderCalendar();
            })
            .catch(function() {
                // Brak pliku lub błąd - kalendarz bez blokad
                renderCalendar();
            });

        var selectedCheckIn = null;
        var selectedCheckOut = null;

        var polishMonths = [
            'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
            'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'
        ];

        function pad(n) { return n < 10 ? '0' + n : '' + n; }

        function dateStr(y, m, d) {
            return y + '-' + pad(m + 1) + '-' + pad(d);
        }

        function isBooked(y, m, d) {
            return bookedSet[dateStr(y, m, d)] === true;
        }

        function isPast(y, m, d) {
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            var check = new Date(y, m, d);
            return check < today;
        }

        function isToday(y, m, d) {
            var today = new Date();
            return y === today.getFullYear() && m === today.getMonth() && d === today.getDate();
        }

        function isInRange(y, m, d) {
            if (!selectedCheckIn || !selectedCheckOut) return false;
            var ds = dateStr(y, m, d);
            return ds > selectedCheckIn && ds < selectedCheckOut;
        }

        function hasBookedInRange(startStr, endStr) {
            for (var key in bookedSet) {
                if (key > startStr && key < endStr) return true;
            }
            return false;
        }

        // Wyjazd w dniu D wymaga wolnych nocy od przyjazdu do D-1 (noc D może być już zajęta).
        function mozliwyWyjazd(ds) {
            return !!selectedCheckIn && !selectedCheckOut && ds > selectedCheckIn &&
                !hasBookedInRange(selectedCheckIn, ds);
        }

        function renderCalendar() {
            calendarDays.innerHTML = '';
            calMonthLabel.textContent = polishMonths[calMonth] + ' ' + calYear;

            // Dzień tygodnia pierwszego dnia miesiąca (0=nd, 1=pn, ... 6=sb)
            var firstDay = new Date(calYear, calMonth, 1).getDay();
            // Konwersja: pn=0, wt=1, ..., nd=6
            var startOffset = firstDay === 0 ? 6 : firstDay - 1;

            var daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();

            // Puste komórki na początku
            for (var i = 0; i < startOffset; i++) {
                var emptyDiv = document.createElement('div');
                emptyDiv.className = 'cal-day cal-empty';
                calendarDays.appendChild(emptyDiv);
            }

            // Dni miesiąca
            for (var day = 1; day <= daysInMonth; day++) {
                var dayDiv = document.createElement('div');
                dayDiv.className = 'cal-day';
                dayDiv.textContent = day;
                dayDiv.setAttribute('data-date', dateStr(calYear, calMonth, day));

                if (isPast(calYear, calMonth, day)) {
                    dayDiv.classList.add('cal-past');
                } else if (isBooked(calYear, calMonth, day)) {
                    // Zajęta noc. Dzień przyjazdu kolejnego gościa może być dniem wyjazdu:
                    // gdy noce od przyjazdu do dnia przed nim są wolne, można go kliknąć jako wyjazd.
                    var dsZ = dateStr(calYear, calMonth, day);
                    if (dsZ === selectedCheckOut) {
                        dayDiv.classList.add('cal-selected');
                        // Ponowny klik czyści wybór (zajęty dzień nie może być przyjazdem).
                        dayDiv.addEventListener('click', function () {
                            selectedCheckIn = null;
                            selectedCheckOut = null;
                            updatePanel();
                            renderCalendar();
                        });
                    } else if (mozliwyWyjazd(dsZ)) {
                        dayDiv.classList.add('cal-wyjazd');
                        dayDiv.title = 'Możliwy wyjazd (tego dnia przyjeżdżają kolejni goście)';
                        (function (dateString) {
                            dayDiv.addEventListener('click', function () {
                                handleDayClick(dateString);
                            });
                        })(dsZ);
                    } else {
                        dayDiv.classList.add('cal-booked');
                        dayDiv.title = 'Termin zajęty';
                    }
                } else {
                    dayDiv.classList.add('cal-free');

                    // Zaznaczenie wybranych dat
                    var ds = dateStr(calYear, calMonth, day);
                    if (ds === selectedCheckIn || ds === selectedCheckOut) {
                        dayDiv.classList.add('cal-selected');
                    } else if (isInRange(calYear, calMonth, day)) {
                        dayDiv.classList.add('cal-range');
                    }

                    // Kliknięcie - wybierz datę
                    (function (dateString, dayNum) {
                        dayDiv.addEventListener('click', function () {
                            handleDayClick(dateString);
                        });
                    })(ds, day);
                }

                if (isToday(calYear, calMonth, day)) {
                    dayDiv.classList.add('cal-today');
                }

                calendarDays.appendChild(dayDiv);
            }
        }

        var bookingStep = document.getElementById('bookingStep');
        var bookingBtn  = document.getElementById('bookingBtn');

        function formatPL(isoStr) {
            if (!isoStr) return '';
            var p = isoStr.split('-');
            return p[2] + '.' + p[1] + '.' + p[0];
        }

        function updatePanel() {
            if (!selectedCheckIn) {
                if (bookingStep) bookingStep.textContent = '\u2190 Kliknij dat\u0119 przyjazdu w kalendarzu';
                if (checkInInput)  { checkInInput.value = ''; checkInInput.removeAttribute('data-iso'); }
                if (checkOutInput) { checkOutInput.value = ''; checkOutInput.removeAttribute('data-iso'); }
                if (bookingBtn) bookingBtn.disabled = true;
            } else if (!selectedCheckOut) {
                if (bookingStep) bookingStep.textContent = '\u2190 Teraz kliknij dat\u0119 wyjazdu';
                if (checkInInput)  { checkInInput.value = formatPL(selectedCheckIn); checkInInput.setAttribute('data-iso', selectedCheckIn); }
                if (checkOutInput) { checkOutInput.value = ''; checkOutInput.removeAttribute('data-iso'); }
                if (bookingBtn) bookingBtn.disabled = true;
            } else {
                if (bookingStep) bookingStep.textContent = '\u2714 Daty wybrane - kliknij przycisk poni\u017cej';
                if (checkInInput)  { checkInInput.value = formatPL(selectedCheckIn); checkInInput.setAttribute('data-iso', selectedCheckIn); }
                if (checkOutInput) { checkOutInput.value = formatPL(selectedCheckOut); checkOutInput.setAttribute('data-iso', selectedCheckOut); }
                if (bookingBtn) bookingBtn.disabled = false;
            }
        }

        function handleDayClick(dateString) {
            if (!selectedCheckIn || (selectedCheckIn && selectedCheckOut)) {
                // Pierwszy klik lub reset - ustaw check-in
                selectedCheckIn = dateString;
                selectedCheckOut = null;
            } else {
                // Drugi klik - ustaw check-out
                if (dateString <= selectedCheckIn) {
                    // Kliknął wcześniejszą lub tę samą datę - nowy check-in
                    selectedCheckIn = dateString;
                    selectedCheckOut = null;
                } else if (hasBookedInRange(selectedCheckIn, dateString)) {
                    // Zarezerwowane dni w środku zakresu - nowy check-in od tej daty
                    selectedCheckIn = dateString;
                    selectedCheckOut = null;
                } else {
                    selectedCheckOut = dateString;
                }
            }
            updatePanel();
            renderCalendar();
        }

        calPrev.addEventListener('click', function () {
            calMonth--;
            if (calMonth < 0) { calMonth = 11; calYear--; }
            renderCalendar();
        });

        calNext.addEventListener('click', function () {
            calMonth++;
            if (calMonth > 11) { calMonth = 0; calYear++; }
            renderCalendar();
        });

        // Nie pozwól cofnąć się przed bieżący miesiąc
        calPrev.addEventListener('click', function () {
            var now = new Date();
            if (calYear < now.getFullYear() || (calYear === now.getFullYear() && calMonth < now.getMonth())) {
                calYear = now.getFullYear();
                calMonth = now.getMonth();
                renderCalendar();
            }
        });

    }

});

// ============================================
// ZGODA NA STATYSTYKI I GOOGLE ANALYTICS 4
// GA4 ładuje się dopiero po kliknięciu „Akceptuję”. Bez zgody nic nie trafia do Google.
// ============================================
(function () {
    var GA = 'G-1QFL1VS2RW';
    var KLUCZ = 'ws-zgoda';
    var gaAktywny = false;   // skrypt GA wczytany na tej stronie
    var zgodaTeraz = false;  // bieżąca zgoda; po wycofaniu żadne zdarzenie nie trafia do Google

    function czytajZgode() {
        try {
            var z = JSON.parse(localStorage.getItem(KLUCZ) || 'null');
            return z && (z.v === 'tak' || z.v === 'nie') ? z.v : null;
        } catch (e) { return null; }
    }
    function zapiszZgode(v) {
        try { localStorage.setItem(KLUCZ, JSON.stringify({ v: v, t: Date.now() })); } catch (e) {}
    }
    function usunCookiesGA() {
        var domeny = ['', '; domain=' + location.hostname, '; domain=.' + location.hostname.replace(/^www\./, '')];
        document.cookie.split(';').forEach(function (c) {
            var n = c.split('=')[0].trim();
            if (n === '_ga' || n.indexOf('_ga_') === 0 || n === '_gid') {
                domeny.forEach(function (d) {
                    document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + d;
                });
            }
        });
    }
    function wlaczGA() {
        zgodaTeraz = true;
        window['ga-disable-' + GA] = false;
        if (gaAktywny) { window.gtag('consent', 'update', { analytics_storage: 'granted' }); return; }
        gaAktywny = true;
        window.dataLayer = window.dataLayer || [];
        window.gtag = function () { window.dataLayer.push(arguments); };
        window.gtag('consent', 'default', { analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
        window.gtag('consent', 'update', { analytics_storage: 'granted' });
        window.gtag('js', new Date());
        window.gtag('config', GA);
        var sc = document.createElement('script');
        sc.async = true;
        sc.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA);
        document.head.appendChild(sc);
    }
    function wylaczGA() {
        zgodaTeraz = false;
        window['ga-disable-' + GA] = true; // oficjalny wyłącznik gtag: zero wysyłek po odmowie
        if (gaAktywny && window.gtag) window.gtag('consent', 'update', { analytics_storage: 'denied' });
        usunCookiesGA();
    }

    // Zdarzenie do GA tylko przy zgodzie, bez danych osobowych. gotowe() po wysłaniu albo po 800 ms.
    window.willaZdarzenie = function (nazwa, parametry, gotowe) {
        if (!(zgodaTeraz && gaAktywny && window.gtag)) { if (gotowe) gotowe(); return; }
        var zrobione = false;
        function koniec() { if (!zrobione) { zrobione = true; if (gotowe) gotowe(); } }
        var p = parametry || {};
        if (gotowe) { p.event_callback = koniec; setTimeout(koniec, 800); }
        p.transport_type = 'beacon';
        window.gtag('event', nazwa, p);
    };

    function zbudujPasek() {
        var bar = document.createElement('div');
        bar.className = 'cookie-bar';
        bar.setAttribute('role', 'region');
        bar.setAttribute('aria-label', 'Zgoda na pliki cookie');
        bar.hidden = true;
        var sciezka = location.pathname.indexOf('/blog/') !== -1 ? '../' : '';
        bar.innerHTML =
            '<p>Za Twoją zgodą używamy Google Analytics, żeby wiedzieć, które informacje o domku są przydatne. ' +
            'Bez zgody strona działa tak samo. <a href="' + sciezka + 'regulamin.html#polityka">Szczegóły</a></p>' +
            '<div class="cookie-btns">' +
            '<button type="button" class="cookie-btn cookie-btn-tak" data-zgoda="tak">Akceptuję</button>' +
            '<button type="button" class="cookie-btn" data-zgoda="nie">Odrzucam</button>' +
            '</div>';
        // na początku body: przy pierwszej wizycie klawiatura trafia na pasek od razu
        document.body.insertBefore(bar, document.body.firstChild);
        return bar;
    }

    function start() {
        var bar = zbudujPasek();
        var zg = czytajZgode();
        if (zg === 'tak') wlaczGA();
        else if (zg === null) bar.hidden = false;

        bar.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-zgoda]');
            if (!b) return;
            var v = b.getAttribute('data-zgoda');
            zapiszZgode(v);
            bar.hidden = true;
            if (document.activeElement && bar.contains(document.activeElement)) document.activeElement.blur();
            if (v === 'tak') wlaczGA(); else wylaczGA();
        });

        // Link „Ustawienia cookies” w stopce, obok polityki prywatności
        var boxy = document.querySelectorAll('.footer-bottom-links');
        if (!boxy.length) boxy = document.querySelectorAll('.footer-bottom');
        Array.prototype.forEach.call(boxy, function (box) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'cookie-ustawienia';
            b.textContent = 'Ustawienia cookies';
            b.addEventListener('click', function () { bar.hidden = false; bar.querySelector('[data-zgoda]').focus(); });
            box.appendChild(b);
        });

        // Kliknięcia, które prowadzą do rezerwacji (bez danych osobowych)
        document.addEventListener('click', function (ev) {
            var a = ev.target.closest('a[href]');
            if (!a) return;
            var h = a.getAttribute('href') || '';
            var strona = location.pathname;
            if (h.indexOf('tel:') === 0) window.willaZdarzenie('klik_telefon', { strona: strona });
            else if (h.indexOf('mailto:') === 0) window.willaZdarzenie('klik_mail', { strona: strona });
            else if (/rezerwacje\.html/.test(h)) window.willaZdarzenie('klik_rezerwuj', { strona: strona, miejsce: a.className || 'link' });
            else if (/booking\.com|airbnb\./.test(h)) window.willaZdarzenie('klik_portal', { strona: strona, portal: /airbnb/.test(h) ? 'airbnb' : 'booking' });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
