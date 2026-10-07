<div class="container">
   <?php
   global $wpdb;

   $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d", 'libro', 3));

   echo '<h1>' . $count . '</h1>';

   if (1 == 2) {

      $usuario_id = 3;
      $tipo = 'libro';
      $año = 2026;
      $mes = 3; // 1 a 12
   
      // Validación básica: sin esto, un valor erróneo borraría otro período.
      if ($mes < 1 || $mes > 12 || $año < 2000 || $año > 2100) {
         echo "Año o mes inválido, no se hizo nada.\n";
         return;
      }

      // Rango [desde, hasta): del día 1 del mes al día 1 del mes siguiente.
      $desde = sprintf('%04d-%02d-01 00:00:00', $año, $mes);
      $hasta = gmdate('Y-m-d H:i:s', strtotime($desde . ' +1 month'));

      // Vista previa: cuántos se van a borrar.
      $total = (int) $wpdb->get_var($wpdb->prepare(
         "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = %s AND post_author = %d
            AND post_date >= %s AND post_date < %s",
         $tipo,
         $usuario_id,
         $desde,
         $hasta
      ));
      echo "Movimientos a borrar entre {$desde} y {$hasta}: {$total}\n";

      if ($total === 0) {
         echo "Nada que borrar.\n";
         return;
      }

      $wpdb->query('START TRANSACTION');

      // Meta de los movimientos.
      $r1 = $wpdb->query($wpdb->prepare(
         "DELETE pm FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = %s AND p.post_author = %d
            AND p.post_date >= %s AND p.post_date < %s",
         $tipo,
         $usuario_id,
         $desde,
         $hasta
      ));

      // Relaciones con categorías (taxonomía sgf_igt).
      $r2 = $wpdb->query($wpdb->prepare(
         "DELETE tr FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
            WHERE p.post_type = %s AND p.post_author = %d
            AND p.post_date >= %s AND p.post_date < %s",
         $tipo,
         $usuario_id,
         $desde,
         $hasta
      ));

      // Los movimientos, al final.
      $r3 = $wpdb->query($wpdb->prepare(
         "DELETE FROM {$wpdb->posts}
            WHERE post_type = %s AND post_author = %d
            AND post_date >= %s AND post_date < %s",
         $tipo,
         $usuario_id,
         $desde,
         $hasta
      ));

      if (false === $r1 || false === $r2 || false === $r3) {
         $wpdb->query('ROLLBACK');
         echo "Error, no se aplicó nada: " . $wpdb->last_error . "\n";
         return;
      }

      $wpdb->query('COMMIT');
      echo "Movimientos borrados: {$r3} (meta: {$r1}, relaciones: {$r2})\n";

      // Contadores de términos y caché.
      $tt_ids = get_terms(['taxonomy' => 'sgf_igt', 'hide_empty' => false, 'fields' => 'tt_ids']);
      if (!is_wp_error($tt_ids) && $tt_ids) {
         wp_update_term_count_now($tt_ids, 'sgf_igt');
      }
      wp_cache_flush();
      echo "Contadores recontados y caché vaciada.\n";
   }
   ?>
</div>


<div class="container">
   <?php
   $libros = get_posts([
      'post_type' => 'libro',
      'author' => 3,
      'posts_per_page' => -1,
      'date_query' => [
         'year' => 2021,
      ],
   ]);

   $contador = 1;
   foreach ($libros as $libro) {
      echo '<h2>' . $contador . '. ' . $libro->post_title . '</h2>';
      $contador++;
   }

   ?>
</div>
