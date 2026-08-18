<footer class="footer bg-white border-top py-3 px-4">
    <div class="d-flex justify-content-between align-items-center">
        <div style="flex: 1;"></div>

        <div class="text-center" style="flex: 2;">
            <span class="text-muted">
                &copy; <?php echo date('Y'); ?> <strong>Designed By Echo-Prime Tech. All Rights Reserved</strong>
            </span>
        </div>

        <div class="text-end text-muted small" style="flex: 1; font-family: 'Courier New', Courier, monospace;">
            <i class="mdi mdi-calendar-clock"></i> 
            <span id="live_date"><?php echo date('d M Y'); ?></span> | 
            <span id="live_clock" class="fw-bold"><?php echo date('H:i:s'); ?></span>
        </div>
    </div>
</footer>

<script>
    function updateClock() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        
        // Update the clock element
        document.getElementById('live_clock').textContent = hours + ":" + minutes + ":" + seconds;
    }

    // Update every 1 second
    setInterval(updateClock, 1000);
</script>