<?php
/**
 * admin/02-employees/_view_attendance.php  -  Attendance tab body.
 * Included by view.php. Expects: $id, $employee, $spanMode, $from, $to,
 * $spanLabel, $calendar, $workedDays, $summary, $pickerDay, $pickerMonth.
 *
 * Day/month picker + summary + a row per calendar day (Yes/No attendance).
 * Each attended day has a "View" button -> day.php.
 */

declare(strict_types=1);
?>

  <!-- pick a day or a month -->
  <div class="span-picker">
    <?php
    $dayModeUrl   = APP_URL . '/admin/02-employees/view.php?' . http_build_query(
        ['id' => $id, 'tab' => 'attendance', 'mode' => 'day', 'day' => $pickerDay]) . '#tabs';
    $monthModeUrl = APP_URL . '/admin/02-employees/view.php?' . http_build_query(
        ['id' => $id, 'tab' => 'attendance', 'mode' => 'month', 'month' => $pickerMonth]) . '#tabs';
    ?>
    <div class="span-picker__modes">
      <a class="span-mode <?= $spanMode === 'day'   ? 'is-active' : '' ?>" href="<?= e($dayModeUrl) ?>">Single day</a>
      <a class="span-mode <?= $spanMode === 'month' ? 'is-active' : '' ?>" href="<?= e($monthModeUrl) ?>">Whole month</a>
    </div>

    <?php if ($spanMode === 'day'): ?>
      <form method="get" action="<?= e(APP_URL) ?>/admin/02-employees/view.php" class="span-picker__form">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="hidden" name="tab" value="attendance">
        <input type="hidden" name="mode" value="day">
        <input class="input" type="date" name="day" value="<?= e($pickerDay) ?>"
               max="<?= e(server_today()) ?>" onchange="this.form.submit()">
        <button type="submit" class="btn btn--sm"><i class="bi bi-calendar-event"></i> Show</button>
      </form>
    <?php else: ?>
      <form method="get" action="<?= e(APP_URL) ?>/admin/02-employees/view.php" class="span-picker__form">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="hidden" name="tab" value="attendance">
        <input type="hidden" name="mode" value="month">
        <input class="input" type="month" name="month" value="<?= e($pickerMonth) ?>"
               max="<?= e(substr(server_today(), 0, 7)) ?>" onchange="this.form.submit()">
        <button type="submit" class="btn btn--sm"><i class="bi bi-calendar-event"></i> Show</button>
      </form>
    <?php endif; ?>

    <span class="span-picker__label">Showing <strong><?= e($spanLabel) ?></strong></span>
  </div>

  <!-- summary for the span -->
  <?php
  $hasData   = $summary['days'] > 0;
  $totalSecs = $summary['total_secs'] > 0 ? $summary['total_secs'] : ($summary['shop_secs'] + $summary['road_secs']);
  $prodPct   = $totalSecs > 0 ? round($summary['shop_secs'] / $totalSecs * 100) : 0;
  $cards = [
      ['Days worked', number_format($summary['days']),
          $summary['incomplete'] ? $summary['incomplete'] . ' incomplete' : ($hasData ? 'all closed' : ''), 'bi-calendar-check'],
      ['Visits', number_format($summary['visits']),
          $hasData ? 'shops visited' : '', 'bi-geo-alt'],
      ['Distance', number_format($summary['road_km'], 1) . ' km', $hasData ? 'road distance' : '', 'bi-signpost-split'],
      ['Total hours', $hasData ? hm($totalSecs) : 'No data', $hasData ? hm($summary['road_secs']) . ' on the road' : '', 'bi-clock'],
      ['Productive hours', $hasData ? hm($summary['shop_secs']) : 'No data', $hasData ? 'at shops, ' . $prodPct . '% of day' : '', 'bi-check2-circle'],
  ];
  ?>
  <div class="summary-row">
    <?php foreach ($cards as [$label, $value, $sub, $icon]): ?>
      <div class="sum-card">
        <span class="sum-card__icon"><i class="bi <?= e($icon) ?>"></i></span>
        <div>
          <div class="sum-card__value"><?= e($value) ?></div>
          <div class="sum-card__label"><?= e($label) ?></div>
          <?php if ($sub !== ''): ?><div class="sum-card__sub"><?= e($sub) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- days -->
  <div class="card">
    <div class="card__head">
      <h2><?= $spanMode === 'day' ? 'That day' : 'Days in ' . e($spanLabel) ?></h2>
      <span class="section-note">
        <?= count($workedDays) ?> attended<?php if ($spanMode === 'month'): ?> of <?= count($calendar) ?> days<?php endif; ?>
      </span>
    </div>

    <?php if (!$calendar): ?>
      <p class="section-note">Nothing to show for <?= e($spanLabel) ?>.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table days-table">
          <thead>
            <tr>
              <th>Date</th><th>Attendance</th><th>Check-in</th><th>Check-out</th>
              <th>Total time</th><th>Shop time</th><th>Travel time</th><th>Distance</th><th>Visits</th>
              <th>Status</th><th class="ta-right"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($calendar as $row):
              $date = $row['date'];
              $dObj = new DateTimeImmutable($date);
              $dayUrl = APP_URL . '/admin/02-employees/day.php?employee=' . $id . '&date=' . $date;
            ?>
            <?php if (!$row['has_attendance']): ?>
              <tr class="day-row day-row--absent">
                <td><span class="c-muted"><?= e($dObj->format('D, j M')) ?></span></td>
                <td><span class="att-no"><i class="bi bi-x-circle"></i> No</span></td>
                <td colspan="7" class="c-muted att-absent-note">No check-in recorded</td>
                <td></td><td></td>
              </tr>
            <?php else:
              $d  = $row;
              $ci = new DateTimeImmutable($d['check_in_at']);
              $co = $d['check_out_at'] ? new DateTimeImmutable($d['check_out_at']) : null;
              $ciLL = latlng((float) $d['check_in_lat'], (float) $d['check_in_lng']);
              $coLL = ($co && $d['check_out_lat'] !== null) ? latlng((float) $d['check_out_lat'], (float) $d['check_out_lng']) : '';
            ?>
              <tr class="day-row">
                <td><strong><?= e($dObj->format('D, j M')) ?></strong></td>
                <td><span class="att-yes"><i class="bi bi-check-circle-fill"></i> Yes</span></td>
                <td>
                  <div class="stack">
                    <span><?= e($ci->format('g:i A')) ?></span>
                    <?php if ($ciLL !== ''): ?><span class="pt-coord"><i class="bi bi-geo-alt"></i> <?= e($ciLL) ?></span><?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php if ($co): ?>
                    <div class="stack">
                      <span><?= e($co->format('g:i A')) ?></span>
                      <?php if ($coLL !== ''): ?><span class="pt-coord"><i class="bi bi-geo-alt"></i> <?= e($coLL) ?></span><?php endif; ?>
                    </div>
                  <?php else: ?><?= dash() ?><?php endif; ?>
                </td>
                <td><?= $d['total_seconds'] !== null ? e(hm((int) $d['total_seconds'])) : dash() ?></td>
                <td><?= e(hm((int) $d['shop_seconds'])) ?></td>
                <td><?= e(hm((int) $d['road_seconds'])) ?></td>
                <td><?= e(number_format((float) $d['road_km'], 1)) ?> km</td>
                <td><?= (int) $d['visit_count'] ?></td>
                <td>
                  <span class="badge badge--<?= $d['status'] === 'closed' ? 'approved' : ($d['status'] === 'incomplete' ? 'rejected' : 'open') ?>">
                    <?= e(ucfirst($d['status'])) ?>
                  </span>
                </td>
                <td class="ta-right">
                  <a class="row-btn row-btn--view" href="<?= e($dayUrl) ?>"><i class="bi bi-eye"></i> View</a>
                </td>
              </tr>
            <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
