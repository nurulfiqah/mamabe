<?php /* outlet registration display - data prepared by mamabe_outlet_data.php */ ?>
<table class="myTable alpro-table" width="100%">
  <thead>
    <tr>
      <th width="40">#</th>
      <th>Outlet</th>
      <th width="120" class="alpro-center">Mama</th>
      <th width="120" class="alpro-center">Baby</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($od_rows)): ?>
    <tr><td colspan="4" class="alpro-center alpro-muted">No outlets found.</td></tr>
  <?php else: $od_i = 1; foreach ($od_rows as $r): ?>
    <tr>
      <td class="alpro-center"><?php echo $od_i++; ?></td>
      <td><?php echo htmlspecialchars(MamabeRecords::outletName($r['comp_name'])); ?></td>
      <td class="alpro-center"><?php echo (int)$r['mamas']; ?></td>
      <td class="alpro-center"><?php echo (int)$r['babies']; ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
  <tfoot>
    <tr style="font-weight:bold; background:#f5f9ff;">
      <td colspan="2" class="alpro-center">Total</td>
      <td class="alpro-center"><?php echo $od_total_mamas; ?></td>
      <td class="alpro-center"><?php echo $od_total_babies; ?></td>
    </tr>
  </tfoot>
</table>
