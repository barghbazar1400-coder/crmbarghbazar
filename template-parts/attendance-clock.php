<?php
/**
 * Employee clock and leave form; only own leave request statuses are visible.
 */
defined( 'ABSPATH' ) || exit;
if ( ! is_user_logged_in() ) { return; }

global $wpdb;
$crmb_leave_rows = array();
if ( '1' === get_option( 'crmb_leave_schema_v1' ) ) {
    $table = crmb_leave_table();
    $crmb_leave_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id,leave_day,time_from,time_to,status,created_utc FROM {$table}
         WHERE user_id = %d ORDER BY id DESC LIMIT 30",
        get_current_user_id()
    ) );
}
$clock_notices = array(
    'in' => 'ورود شما ثبت شد.',
    'out' => 'خروج شما ثبت شد.',
    'already' => 'این عملیات قبلاً ثبت شده است.',
    'missing_in' => 'ورود باز و معتبری برای خروج پیدا نشد؛ با مدیر هماهنگ کنید.',
    'closed' => 'ثبت این ماه بسته شده است؛ با مدیر هماهنگ کنید.',
    'invalid' => 'عملیات انجام نشد. دوباره تلاش کنید.',
);
$leave_notices = array(
    'submitted' => 'درخواست مرخصی ثبت شد و برای بررسی مدیر کل ارسال شد.',
    'invalid' => 'تاریخ یا ساعت معتبر نیست. تاریخ شمسی و بازه زمانی را بررسی کنید.',
    'overlap' => 'برای این بازه زمانی، درخواست تأییدشده یا در انتظار بررسی دارید.',
    'error' => 'خطا در ثبت درخواست. دوباره تلاش کنید.',
);
$clock_notice = isset( $_GET['clock_notice'] ) && is_string( $_GET['clock_notice'] )
    ? sanitize_key( wp_unslash( $_GET['clock_notice'] ) ) : '';
$leave_notice = isset( $_GET['leave_notice'] ) && is_string( $_GET['leave_notice'] )
    ? sanitize_key( wp_unslash( $_GET['leave_notice'] ) ) : '';
$timezone = wp_timezone_string();
$clock_offset_seconds = ( new DateTimeImmutable( 'now', wp_timezone() ) )->getOffset();
?>
<section class="crmb-att crmb-att-clock-screen" aria-label="ثبت تردد و مرخصی">
    <div class="crmb-att-clock-card">
        <header class="crmb-att-live-header">
            <div class="crmb-att-live-title">
                <h1>ثبت تردد</h1>
                <p>ثبت ساعت ورود و خروج بر اساس زمان سرور انجام می‌شود.</p>
            </div>
            <div class="crmb-att-live-display" data-clock-timezone="<?php echo esc_attr( $timezone ); ?>" data-clock-offset="<?php echo esc_attr( $clock_offset_seconds ); ?>">
                <time class="crmb-att-live-date" id="crmb-live-date" aria-label="تاریخ امروز"><?php echo esc_html( crmb_att_jdate( wp_date( 'Y-m-d' ) ) ); ?></time>
                <span class="crmb-att-live-time" id="crmb-live-time" dir="ltr" aria-label="ساعت جاری"><?php echo esc_html( wp_date( 'H:i:s' ) ); ?></span>
            </div>
        </header>
        <?php if ( isset( $clock_notices[ $clock_notice ] ) ) : ?>
            <p class="crmb-att-alert" role="status"><?php echo esc_html( $clock_notices[ $clock_notice ] ); ?></p>
        <?php endif; ?>
        <div class="crmb-att-clock-actions">
            <div class="crmb-att-clock-buttons">
                <?php foreach ( array( 'in' => 'ثبت ورود', 'out' => 'ثبت خروج' ) as $action => $label ) : ?>
                    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                        <input type="hidden" name="action" value="crmb_att_clock">
                        <input type="hidden" name="clock_action" value="<?php echo esc_attr( $action ); ?>">
                        <?php wp_nonce_field( 'crmb_att_clock', 'crmb_att_nonce' ); ?>
                        <button class="<?php echo 'in' === $action ? 'crmb-att-primary' : 'crmb-att-secondary'; ?>" type="submit"><?php echo esc_html( $label ); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
            <p class="crmb-att-hint">گزارش حضور، ساعات فعالیت، اضافه‌کاری و تأخیر فقط برای مدیر کل نمایش داده می‌شود.</p>
        </div>
    </div>

    <section class="crmb-att-card crmb-att-leave-card">
        <div class="crmb-att-leave-heading">
            <h2>درخواست مرخصی</h2>
            <p>تاریخ موردنظر، ساعت شروع و پایان مرخصی را مشخص کنید. توضیحات اختیاری است.</p>
        </div>
        <?php if ( isset( $leave_notices[ $leave_notice ] ) ) : ?>
            <p class="crmb-att-alert" role="status"><?php echo esc_html( $leave_notices[ $leave_notice ] ); ?></p>
        <?php endif; ?>
        <form class="crmb-att-leave-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="crmb_leave_submit">
            <?php wp_nonce_field( 'crmb_leave_submit', 'crmb_leave_nonce' ); ?>
            <div class="crmb-att-leave-fields">
                <div class="crmb-att-leave-field">
                    <span class="crmb-att-field-label" id="crmb-leave-date-label">تاریخ مرخصی (شمسی)</span>
                    <input type="hidden" name="leave_day" id="crmb-leave-day" value="">
                    <div class="crmb-att-date-picker" id="crmb-leave-date-picker"
                         data-today="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"
                         data-last-day="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+1 year' ) ) ); ?>">
                        <button type="button" class="crmb-att-date-trigger" id="crmb-leave-date-trigger"
                                aria-labelledby="crmb-leave-date-label crmb-leave-date-value"
                                aria-haspopup="dialog" aria-expanded="false" aria-controls="crmb-leave-calendar">
                            <span id="crmb-leave-date-value">انتخاب تاریخ از تقویم</span>
                            <span class="crmb-att-calendar-icon" aria-hidden="true">▦</span>
                        </button>
                        <div class="crmb-att-calendar" id="crmb-leave-calendar" role="dialog" aria-label="تقویم انتخاب تاریخ مرخصی" hidden>
                            <div class="crmb-att-calendar-heading">
                                <button type="button" id="crmb-calendar-prev" aria-label="ماه قبل">‹</button>
                                <strong id="crmb-calendar-month" aria-live="polite"></strong>
                                <button type="button" id="crmb-calendar-next" aria-label="ماه بعد">›</button>
                            </div>
                            <div class="crmb-att-calendar-grid crmb-att-calendar-weekdays" aria-hidden="true">
                                <span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span>
                            </div>
                            <div class="crmb-att-calendar-grid crmb-att-calendar-days" id="crmb-calendar-days"></div>
                        </div>
                    </div>
                </div>
                <?php foreach ( array( 'from' => 'از ساعت', 'to' => 'تا ساعت' ) as $side => $side_label ) : ?>
                    <div class="crmb-att-leave-field">
                        <span class="crmb-att-field-label" id="crmb-leave-<?php echo esc_attr( $side ); ?>-label"><?php echo esc_html( $side_label ); ?></span>
                        <input type="hidden" name="time_<?php echo esc_attr( $side ); ?>" id="crmb-leave-<?php echo esc_attr( $side ); ?>" value="">
                        <div class="crmb-att-time-parts" role="group" aria-labelledby="crmb-leave-<?php echo esc_attr( $side ); ?>-label">
                            <select id="crmb-leave-<?php echo esc_attr( $side ); ?>-hour" data-time-part="<?php echo esc_attr( $side ); ?>" aria-label="ساعت <?php echo esc_attr( $side_label ); ?>" required>
                                <option value="">ساعت</option>
                                <?php for ( $h = 0; $h <= 23; $h++ ) : ?>
                                    <option value="<?php echo esc_attr( sprintf( '%02d', $h ) ); ?>"><?php echo esc_html( sprintf( '%02d', $h ) ); ?></option>
                                <?php endfor; ?>
                            </select>
                            <span aria-hidden="true">:</span>
                            <select id="crmb-leave-<?php echo esc_attr( $side ); ?>-minute" data-time-part="<?php echo esc_attr( $side ); ?>" aria-label="دقیقه <?php echo esc_attr( $side_label ); ?>" required>
                                <option value="">دقیقه</option>
                                <?php for ( $m = 0; $m <= 59; $m++ ) : ?>
                                    <option value="<?php echo esc_attr( sprintf( '%02d', $m ) ); ?>"><?php echo esc_html( sprintf( '%02d', $m ) ); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <label class="crmb-att-leave-reason" for="crmb-leave-reason">توضیحات (اختیاری)
                <textarea id="crmb-leave-reason" name="reason" rows="4" maxlength="1000"
                          placeholder="در صورت نیاز، دلیل درخواست مرخصی را توضیح دهید."></textarea>
            </label>
            <p class="crmb-att-leave-error" id="crmb-leave-client-error" role="alert" hidden></p>
            <button type="submit" class="crmb-att-primary crmb-att-leave-submit">ارسال درخواست مرخصی</button>
        </form>
    </section>

    <section class="crmb-att-card crmb-att-leave-history">
        <h2>وضعیت درخواست‌های مرخصی من</h2>
        <div class="crmb-att-table-wrap">
            <table class="crmb-att-table">
                <thead><tr><th>تاریخ مرخصی</th><th>ساعت شروع</th><th>ساعت پایان</th><th>نتیجه بررسی</th></tr></thead>
                <tbody>
                <?php foreach ( $crmb_leave_rows as $request ) : ?>
                    <tr>
                        <td dir="ltr"><?php echo esc_html( crmb_att_jdate( $request->leave_day ) ); ?></td>
                        <td dir="ltr"><?php echo esc_html( $request->time_from ); ?></td>
                        <td dir="ltr"><?php echo esc_html( $request->time_to ); ?></td>
                        <td>
                            <span class="crmb-leave-status crmb-leave-status-<?php echo esc_attr( $request->status ); ?>">
                                <?php echo esc_html( crmb_leave_status_label( $request->status ) ); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $crmb_leave_rows ) ) : ?>
                    <tr><td colspan="4" class="crmb-att-no-requests">درخواستی ثبت نشده است.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<script>
(function () {
    "use strict";
    const root = document.querySelector(".crmb-att-live-display");
    if (!root) return;
    const dateElement = document.getElementById("crmb-live-date");
    const timeElement = document.getElementById("crmb-live-time");
    const zone = root.dataset.clockTimezone || "Asia/Tehran";
    let dateFormatter, timeFormatter;
    let usesOffset = false;
    const offsetMs = (Number(root.dataset.clockOffset) || 0) * 1000;
    try {
        dateFormatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian-nu-latn", {
            timeZone: zone, weekday: "long", day: "numeric", month: "long", year: "numeric"
        });
        timeFormatter = new Intl.DateTimeFormat("en-GB", {
            timeZone: zone, hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23"
        });
        dateFormatter.format(new Date());
        timeFormatter.format(new Date());
    } catch (error) {
        try {
            usesOffset = true;
            dateFormatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian-nu-latn", {
                timeZone: "UTC", weekday: "long", day: "numeric", month: "long", year: "numeric"
            });
            timeFormatter = new Intl.DateTimeFormat("en-GB", {
                timeZone: "UTC", hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23"
            });
        } catch (unsupported) {
            return;
        }
    }
    function tick() {
        const now = new Date(Date.now() + (usesOffset ? offsetMs : 0));
        dateElement.textContent = dateFormatter.format(now);
        timeElement.textContent = timeFormatter.format(now);
    }
    tick();
    window.setInterval(tick, 1000);

    // A genuinely clickable Persian calendar; the employee never types a date.
    const picker = document.getElementById("crmb-leave-date-picker");
    const trigger = document.getElementById("crmb-leave-date-trigger");
    const panel = document.getElementById("crmb-leave-calendar");
    const daysGrid = document.getElementById("crmb-calendar-days");
    const monthHeading = document.getElementById("crmb-calendar-month");
    const prev = document.getElementById("crmb-calendar-prev");
    const next = document.getElementById("crmb-calendar-next");
    const dateValue = document.getElementById("crmb-leave-date-value");
    const dateInput = document.getElementById("crmb-leave-day");
    const form = document.querySelector(".crmb-att-leave-form");
    const errorBox = document.getElementById("crmb-leave-client-error");
    if (!picker || !form) return;

    const todayISO = picker.dataset.today;
    const lastDayISO = picker.dataset.lastDay;
    function utcDate(iso) {
        return new Date(iso + "T12:00:00Z");
    }
    function dateISO(day) {
        return day.toISOString().slice(0, 10);
    }
    function plusDays(day, count) {
        const copy = new Date(day.getTime());
        copy.setUTCDate(copy.getUTCDate() + count);
        return copy;
    }
    let visibleDay = utcDate(todayISO);
    let persianParts, persianMonth, persianLongDay, persianDigits;
    try {
        persianParts = new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
            timeZone: "UTC", year: "numeric", month: "numeric", day: "numeric"
        });
        persianMonth = new Intl.DateTimeFormat("fa-IR-u-ca-persian", {
            timeZone: "UTC", year: "numeric", month: "long"
        });
        persianLongDay = new Intl.DateTimeFormat("fa-IR-u-ca-persian", {
            timeZone: "UTC", year: "numeric", month: "long", day: "numeric"
        });
        persianDigits = new Intl.NumberFormat("fa-IR", {useGrouping: false});
    } catch (unsupported) {
        dateValue.textContent = "تقویم شمسی در این مرورگر پشتیبانی نمی‌شود";
        trigger.disabled = true;
        return;
    }
    function jalali(day) {
        const components = {};
        for (const p of persianParts.formatToParts(day)) {
            if (p.type === "year" || p.type === "month" || p.type === "day") {
                components[p.type] = Number(p.value);
            }
        }
        return components;
    }
    function firstInMonth(day) {
        let start = day;
        for (let guard = 0; guard < 32 && jalali(start).day !== 1; guard++) {
            start = plusDays(start, -1);
        }
        return start;
    }
    function monthID(day) {
        const j = jalali(day);
        return j.year * 12 + j.month;
    }
    const minimumMonth = monthID(utcDate(todayISO));
    const maximumMonth = monthID(utcDate(lastDayISO));
    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = !message;
    }
    function closeCalendar() {
        panel.hidden = true;
        trigger.setAttribute("aria-expanded", "false");
    }
    function renderCalendar() {
        const start = firstInMonth(visibleDay);
        const current = jalali(start);
        const activeMonth = current.year * 12 + current.month;
        monthHeading.textContent = persianMonth.format(start);
        prev.disabled = activeMonth <= minimumMonth;
        next.disabled = activeMonth >= maximumMonth;
        daysGrid.replaceChildren();

        // Saturday is the first day of the Iranian week.
        const offset = (start.getUTCDay() + 1) % 7;
        for (let blank = 0; blank < offset; blank++) {
            daysGrid.appendChild(document.createElement("span"));
        }
        for (let index = 0; index < 31; index++) {
            const day = plusDays(start, index);
            const j = jalali(day);
            if (j.year !== current.year || j.month !== current.month) break;
            const iso = dateISO(day);
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = persianDigits.format(j.day);
            button.setAttribute("aria-label", persianLongDay.format(day));
            button.disabled = iso < todayISO || iso > lastDayISO;
            if (iso === todayISO) button.classList.add("is-today");
            if (dateInput.value === j.year + "-" + String(j.month).padStart(2, "0") +
                "-" + String(j.day).padStart(2, "0")) {
                button.classList.add("is-selected");
                button.setAttribute("aria-pressed", "true");
            }
            button.addEventListener("click", function () {
                dateInput.value = j.year + "-" + String(j.month).padStart(2, "0") +
                    "-" + String(j.day).padStart(2, "0");
                dateValue.textContent = persianLongDay.format(day);
                showError("");
                closeCalendar();
                trigger.focus();
            });
            daysGrid.appendChild(button);
        }
    }
    trigger.addEventListener("click", function () {
        if (panel.hidden) {
            renderCalendar();
            panel.hidden = false;
            trigger.setAttribute("aria-expanded", "true");
        } else {
            closeCalendar();
        }
    });
    prev.addEventListener("click", function () {
        if (prev.disabled) return;
        visibleDay = plusDays(firstInMonth(visibleDay), -1);
        renderCalendar();
    });
    next.addEventListener("click", function () {
        if (next.disabled) return;
        visibleDay = plusDays(firstInMonth(visibleDay), 32);
        renderCalendar();
    });
    document.addEventListener("pointerdown", function (event) {
        if (!panel.hidden && !picker.contains(event.target)) closeCalendar();
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !panel.hidden) {
            closeCalendar();
            trigger.focus();
        }
    });

    // Explicit hour/minute menus keep the posted fields in HH:MM format.
    function syncTime(side) {
        const hour = document.getElementById("crmb-leave-" + side + "-hour").value;
        const minute = document.getElementById("crmb-leave-" + side + "-minute").value;
        document.getElementById("crmb-leave-" + side).value =
            hour !== "" && minute !== "" ? hour + ":" + minute : "";
        showError("");
    }
    for (const side of ["from", "to"]) {
        for (const part of ["hour", "minute"]) {
            document.getElementById("crmb-leave-" + side + "-" + part)
                .addEventListener("change", function () { syncTime(side); });
        }
    }
    form.addEventListener("submit", function (event) {
        const from = document.getElementById("crmb-leave-from").value;
        const to = document.getElementById("crmb-leave-to").value;
        if (!dateInput.value) {
            event.preventDefault();
            showError("لطفاً تاریخ مرخصی را از تقویم انتخاب کنید.");
            trigger.focus();
        } else if (!from || !to) {
            event.preventDefault();
            showError("لطفاً ساعت و دقیقه شروع و پایان را انتخاب کنید.");
        } else if (to <= from) {
            event.preventDefault();
            showError("ساعت پایان باید بعد از ساعت شروع مرخصی باشد.");
        }
    });
})();
</script>
