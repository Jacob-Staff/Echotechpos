<footer class="footer bg-white border-top py-3 px-4">
    <div class="row align-items-center text-center text-md-start">
        <div class="col-12 col-md-8 mb-2 mb-md-0">
            <span class="text-muted">
                &copy; <?php echo date('Y'); ?> <strong>Designed By Echo-Prime Tech. All Rights Reserved</strong>
            </span>
        </div>
        <div class="col-12 col-md-4 text-center text-md-end text-muted small" style="font-family: 'Courier New', Courier, monospace;">
            <i class="mdi mdi-calendar-clock me-1"></i> 
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
        
        const clockElement = document.getElementById('live_clock');
        if (clockElement) {
            clockElement.textContent = hours + ":" + minutes + ":" + seconds;
        }
    }

    setInterval(updateClock, 1000);
</script>
