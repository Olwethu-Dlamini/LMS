/*
 * Booking-style date range for Apply for Leave.
 *
 * Wraps Litepicker (assets/plugins/litepicker) around two read-only display
 * inputs and writes the chosen dates, as YYYY-MM-DD, into the hidden
 * start_date / end_date fields the form actually submits. The server stays the
 * judge of what is allowed: LeaveCalculator recounts the days and re-checks
 * notice and balance whatever this widget says.
 *
 * What it adds on top of the plain range picker:
 *   - the hover tooltip counts working days, skipping weekends and public
 *     holidays exactly as LeaveCalculator::calculateWorkingDays does
 *   - weekends and holidays cannot start or end a request, but a range may run
 *     across them; holidays carry their name as a tooltip
 *   - the earliest selectable date follows the chosen leave type's notice period
 */
(function () {
    "use strict";

    function pad(n) {
        return n < 10 ? "0" + n : String(n);
    }

    // Local calendar date, never toISOString(): that is UTC and lands on the
    // previous day for the first two hours of every day in Mbabane.
    function isoDate(date) {
        return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
    }

    function isWeekend(date) {
        const day = date.getDay();
        return day === 0 || day === 6;
    }

    // Litepicker hands back its own DateTime wrapper; work on a plain Date.
    function toDate(value) {
        return value instanceof Date ? value : new Date(value.getTime());
    }

    function plural(n, one, many) {
        return n + " " + (n === 1 ? one : many);
    }

    window.initLeaveRangePicker = function (opts) {
        const holidays = opts.holidays || {};
        const startDisplay = opts.startDisplay;
        const endDisplay = opts.endDisplay;
        const startInput = opts.startInput;
        const endInput = opts.endInput;
        const wide = window.matchMedia("(min-width: 768px)");

        function isClosed(date) {
            return isWeekend(date) || Object.prototype.hasOwnProperty.call(holidays, isoDate(date));
        }

        function workingDaysBetween(a, b) {
            const from = a <= b ? a : b;
            const to = a <= b ? b : a;
            const day = new Date(from.getFullYear(), from.getMonth(), from.getDate());
            let count = 0;
            while (day <= to) {
                if (!isClosed(day)) count++;
                day.setDate(day.getDate() + 1);
            }
            return count;
        }

        function writeHidden(start, end) {
            startInput.value = start ? isoDate(toDate(start)) : "";
            endInput.value = end ? isoDate(toDate(end)) : "";
            startInput.dispatchEvent(new Event("change"));
            endInput.dispatchEvent(new Event("change"));
        }

        function months() {
            return wide.matches ? 2 : 1;
        }

        const picker = new Litepicker({
            element: startDisplay,
            elementEnd: endDisplay,
            singleMode: false,
            allowRepick: true,
            firstDay: 1,
            numberOfMonths: months(),
            numberOfColumns: months(),
            format: "D MMM YYYY",
            startDate: startInput.value || null,
            endDate: endInput.value || null,
            highlightedDays: Object.keys(holidays),
            lockDays: Object.keys(holidays),
            lockDaysFilter: function (date) {
                return isWeekend(toDate(date));
            },
            disallowLockDaysInRange: false,
            tooltipText: { one: "day", other: "days" },
            setup: function (p) {
                p.on("selected", function (start, end) {
                    startDisplay.classList.remove("is-invalid");
                    endDisplay.classList.remove("is-invalid");
                    writeHidden(start, end);
                });

                p.on("clear:selection", function () {
                    writeHidden(null, null);
                });

                // Litepicker has already written a calendar-day count; replace it
                // with the figure the request will actually cost.
                p.on("tooltip", function (tooltip, dayEl) {
                    const picked = p.datePicked && p.datePicked[0];
                    if (!picked || !dayEl || !dayEl.dataset.time) return;
                    const first = toDate(picked);
                    const hovered = new Date(parseInt(dayEl.dataset.time, 10));
                    const calendar = Math.round(Math.abs(hovered - first) / 86400000) + 1;
                    const working = workingDaysBetween(first, hovered);
                    tooltip.textContent = plural(working, "working day", "working days")
                        + (calendar !== working ? " · " + plural(calendar, "day", "days") : "");
                });

                p.on("render:day", function (dayEl, value) {
                    const date = toDate(value);
                    const key = isoDate(date);
                    if (Object.prototype.hasOwnProperty.call(holidays, key)) {
                        dayEl.classList.add("is-holiday");
                        dayEl.title = holidays[key];
                    } else if (isWeekend(date)) {
                        dayEl.classList.add("is-weekend");
                    }
                });
            }
        });

        wide.addEventListener("change", function () {
            picker.setOptions({ numberOfMonths: months(), numberOfColumns: months() });
        });

        // The read-only display inputs are skipped by the browser's required
        // check, so the form asks for the dates itself.
        if (opts.form) {
            opts.form.addEventListener("submit", function (event) {
                if (startInput.value && endInput.value) return;
                event.preventDefault();
                startDisplay.classList.add("is-invalid");
                endDisplay.classList.add("is-invalid");
                picker.show(startDisplay);
            });
        }

        return {
            // ISO date of the first bookable day, or null for a category that
            // may be backdated. A selection that no longer qualifies is dropped.
            setEarliest: function (iso) {
                picker.setOptions({ minDate: iso || null });
                if (iso && startInput.value && startInput.value < iso) {
                    picker.clearSelection();
                }
            }
        };
    };
})();
