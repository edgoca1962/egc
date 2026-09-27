<div class="container py-5">
   <h1>Gráficos</h1>
   <canvas id="myChart"></canvas>
</div>
<script>
   document.addEventListener('DOMContentLoaded', function () {
      const ctx = document.getElementById('myChart').getContext('2d');

      const config = {
         type: 'bar', // Se utiliza el tipo base 'bar'
         data: {
            labels: ['Inicio', 'Ventas', 'Gastos', 'Total'],
            datasets: [{
               label: 'Flujo Financiero',
               // Cada elemento se define con [mínimo, máximo]
               data: [
                  [0, 100],    // Inicio
                  [100, 150],  // Ventas (+50)
                  [120, 150],  // Gastos (-30)
                  [0, 120]     // Total acumulado
               ],
               backgroundColor: [
                  '#36A2EB', // Azul para el inicio
                  '#4BC0C0', // Verde para aumentos
                  '#FF6384', // Rojo para disminuciones
                  '#9966FF'  // Morado para el total
               ]
            }]
         },
         options: {
            responsive: true,
            plugins: {
               tooltip: {
                  callbacks: {
                     // Ajusta el tooltip para mostrar la diferencia neta y no el rango [min, max]
                     label: function (context) {
                        const v = context.dataset.data[context.dataIndex];
                        const diferencia = v[1] - v[0];
                        return context.label + ': ' + diferencia;
                     }
                  }
               }
            }
         }
      };

      const myChart = new Chart(ctx, config);
   });
</script>
