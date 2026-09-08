<?php
/**
 * admin/02-employees/_view_visits.php  -  "Visits History" tab body.
 * Included by view.php. Expects: $id, $employee, $vh (from employee_visits_history()).
 *
 * A flat, paginated list of every visit this Employee made in a chosen month,
 * newest first, with per-visit shop detail. Each row has a "Day" button that
 * opens day.php for that visit's date.
 */

declare(strict_types=1);

$monthLabel = (new DateTimeImmutable($vh['month_from']))->format('F Y');

/** URL to this tab keeping id + tab, overriding some params. #tabs keeps the
 *  viewport at the tab bar instead of jumping to the page top. */
$vhUrl = static function (int $id, array $override): string {
    $q = array_filter(
        array_merge(['id' => $id, 'tab' => 'visits'], array_intersect_key($_GET, array_flip(['month', 'q', 'per_page'])), $override),
        static fn($v) => $v !== '' && $v !== null && $v !== '0'
    );
    return APP_URL . '/admin/02-employees/view.php?' . http_build_query($q) . '#tabs';
};
?>

  <!-- filter bar -->
  <form class="vh-filter" method="get" action="<?= e(APP_URL) ?>/admin/02-employees/view.php">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="tab" value="visits">

    <input class="input" type="month" name="month" value="<?= e($vh['month']) ?>"
           max="<?= e(substr(server_today(), 0, 7)) ?>" onchange="this.form.submit()">

    <div class="vh-filter__search">
      <i class="bi bi-search"></i>
      <input class="input" type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Shop name...">
    </div>

    <button type="submit" class="btn btn--sm"><i class="bi bi-funnel"></i> Filter</button>
    <?php if (($_GET['q'] ?? '') !== ''): ?>
      <a class="btn btn--sm" href="<?= e($vhUrl($id, ['q' => null])) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <!-- month summary strip -->
  <div class="vh-strip">
    <div><strong><?= (int) $vh['totals']['visits'] ?></strong><span>visits in <?= e($monthLabel) ?></span></div>
    <div><strong><?= (int) $vh['totals']['shops'] ?></strong><span>shops visited in <?= e((new DateTimeImmutable($vh['month_from']))->format('F')) ?></span></div>
    <div><strong><?= e(hm($vh['totals']['avg_dwell_secs'], '-')) ?></strong><span>avg. shop visit time</span></div>
  </div>

  <div class="card">
    <div class="card__head">
      <h2>Visits in <?= e($monthLabel) ?></h2>
      <span class="section-note"><?= (int) $vh['total'] ?> match<?= $vh['total'] === 1 ? '' : 'es' ?></span>
    </div>

    <?php if (!$vh['rows']): ?>
      <p class="section-note">
        <?= (($_GET['q'] ?? '') !== '')
            ? 'No visits match this search in ' . e($monthLabel) . '.'
            : 'No visits recorded in ' . e($monthLabel) . '.' ?>
      </p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table visits-table vh-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Shop visited</th>
              <th>Arrived</th>
              <th>Left</th>
              <th>Time spent at the shop</th>
              <th>Drive from the previous stop</th>
              <th>Location accuracy</th>
              <th>Photo proof</th>
              <th class="ta-right"></th>
            </tr>
          </thead>
          <tbody>
            <?php
            $mins = static fn(int $s) => $s < 60 ? $s . ' sec' : round($s / 60) . ' min';
            $accWord = static function (?float $m): string {
                if ($m === null) return '';
                if ($m <= 15) return 'good signal';
                if ($m <= 40) return 'fair signal';
                return 'weak signal';
            };
            foreach ($vh['rows'] as $v):
              $dObj  = new DateTimeImmutable($v['work_date']);
              $arr   = new DateTimeImmutable($v['arrived_at']);
              $lft   = $v['left_at'] ? new DateTimeImmutable($v['left_at']) : null;
              $ll    = latlng((float) $v['lat'], (float) $v['lng']);
              $dayUrl = APP_URL . '/admin/02-employees/day.php?employee=' . $id . '&date=' . $v['work_date'];
            ?>
            <tr>
              <td class="vh-date">
                <strong><?= e($dObj->format('j M Y')) ?></strong>
                <span class="c-muted"><?= e($dObj->format('l')) ?></span>
              </td>
              <td>
                <div class="stack">
                  <strong><?= e($v['shop_name']) ?></strong>
                  <?php if ($ll !== ''): ?><span class="pt-coord">GPS <?= e($ll) ?></span><?php endif; ?>
                  <?php if ($v['remark']): ?>
                    <span class="tl-note"><i class="bi bi-chat-left-text"></i> <?= e($v['remark']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td><?= e($arr->format('g:i A')) ?></td>
              <td><?= $lft ? e($lft->format('g:i A')) : '<span class="c-muted">still there</span>' ?></td>
              <td>
                <?php if ($v['dwell_seconds'] !== null): ?>
                  <strong><?= e($mins((int) $v['dwell_seconds'])) ?></strong>
                <?php else: ?>
                  <span class="c-muted">still there</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($v['hop_seconds'] !== null): ?>
                  <?= e(number_format((float) $v['hop_road_km'], 2)) ?> km drive
                  <?php if ((int) $v['hop_seconds'] > 0): ?>, <?= e($mins((int) $v['hop_seconds'])) ?> driving time<?php endif; ?>
                  <br><span class="c-muted">road distance from the previous stop</span>
                <?php else: ?>
                  <span class="c-muted">-</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($v['accuracy_m'] !== null): ?>
                  within <?= e(number_format((float) $v['accuracy_m'], 0)) ?> m
                  <br><span class="c-muted"><?= e($accWord((float) $v['accuracy_m'])) ?></span>
                <?php else: ?>
                  <?= dash() ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($v['photo_path']): ?>
                  <a href="<?= e(UPLOAD_URL . '/' . $v['photo_path']) ?>" target="_blank" rel="noopener">
                    <img class="table__thumb" src="<?= e(UPLOAD_URL . '/' . $v['photo_path']) ?>" alt="">
                  </a>
                  <?php if ((int) $v['photo_count'] > 1): ?><span class="c-muted">+<?= (int) $v['photo_count'] - 1 ?> more</span><?php endif; ?>
                <?php else: ?>
                  <span class="c-muted">no photo</span>
                <?php endif; ?>
              </td>
              <td class="ta-right">
                <a class="row-btn row-btn--view" href="<?= e($dayUrl) ?>"><i class="bi bi-eye"></i> View day</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- footer: count + pager + page size -->
      <div class="table-foot">
        <?php
          $from = $vh['total'] ? (($vh['page'] - 1) * $vh['per_page']) + 1 : 0;
          $to   = min($vh['page'] * $vh['per_page'], $vh['total']);
        ?>
        <span class="section-note">Showing <?= $from ?> to <?= $to ?> of <?= (int) $vh['total'] ?> visits</span>

        <?php if ($vh['pages'] > 1): ?>
          <div class="pager">
            <?php if ($vh['page'] > 1): ?>
              <a href="<?= e($vhUrl($id, ['page' => $vh['page'] - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
            <?php else: ?><span class="is-disabled"><i class="bi bi-chevron-left"></i></span><?php endif; ?>

            <?php
            $p = $vh['page']; $last = $vh['pages'];
            $show = array_unique(array_filter([1, $p - 1, $p, $p + 1, $last], static fn($n) => $n >= 1 && $n <= $last));
            sort($show);
            $prev = 0;
            foreach ($show as $n):
                if ($n - $prev > 1) echo '<span class="pager__gap">...</span>';
                $prev = $n; ?>
              <a class="<?= $n === $p ? 'is-current' : '' ?>" href="<?= e($vhUrl($id, ['page' => $n])) ?>"><?= $n ?></a>
            <?php endforeach; ?>

            <?php if ($vh['page'] < $last): ?>
              <a href="<?= e($vhUrl($id, ['page' => $vh['page'] + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
            <?php else: ?><span class="is-disabled"><i class="bi bi-chevron-right"></i></span><?php endif; ?>
          </div>
        <?php endif; ?>

        <form method="get" action="<?= e(APP_URL) ?>/admin/02-employees/view.php" class="page-size">
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <input type="hidden" name="tab" value="visits">
          <input type="hidden" name="month" value="<?= e($vh['month']) ?>">
          <?php if (($_GET['q'] ?? '') !== ''): ?><input type="hidden" name="q" value="<?= e($_GET['q']) ?>"><?php endif; ?>
          <select class="select" name="per_page" onchange="this.form.submit()">
            <?php foreach ([10, 25, 50, 100] as $n): ?>
              <option value="<?= $n ?>" <?= $vh['per_page'] === $n ? 'selected' : '' ?>><?= $n ?> / page</option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    <?php endif; ?>
  </div>
