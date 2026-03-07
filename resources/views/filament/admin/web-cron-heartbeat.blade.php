{{-- Web Cron Heartbeat: auto-ping server at configurable interval --}}
{{-- Only active when web cron is enabled in System Settings --}}
@if(\App\Services\WebCronService::isEnabled())
    <script>
        (function () {
            const INTERVAL = @json(\App\Services\WebCronService::getInterval() * 1000); // ms
            const url = @json(route('api.web-cron-ping'));
            const token = @json(\App\Services\WebCronService::generateToken());
            const backgroundMode = @json(\App\Services\WebCronService::isBackgroundEnabled());

            let timer = null;
            let consecutiveErrors = 0;
            const MAX_RETRIES = 5;

            function ping() {
                fetch(url + '?token=' + encodeURIComponent(token), {
                    method: 'GET',
                    keepalive: true,
                })
                    .then(function (response) {
                        if (response.ok) {
                            consecutiveErrors = 0;
                        }
                    })
                    .catch(function () {
                        consecutiveErrors++;

                        // If too many consecutive failures, slow down to avoid hammering
                        if (consecutiveErrors >= MAX_RETRIES) {
                            stop();
                            // Retry after 5 minutes
                            setTimeout(function () {
                                consecutiveErrors = 0;
                                start();
                            }, 300000);
                        }
                    });
            }

            function start() {
                if (timer) return;
                timer = setInterval(ping, INTERVAL);
            }

            function stop() {
                if (timer) {
                    clearInterval(timer);
                    timer = null;
                }
            }

            // Visibility handling: pause/resume based on background mode setting
            document.addEventListener('visibilitychange', function () {
                if (backgroundMode) {
                    // Background mode ON: never stop, just ping when tab becomes visible
                    if (!document.hidden) {
                        ping();
                    }
                } else {
                    // Background mode OFF: stop when hidden, resume when visible
                    if (document.hidden) {
                        stop();
                    } else {
                        start();
                        ping();
                    }
                }
            });

            // Start heartbeat
            if (!document.hidden || backgroundMode) {
                start();
                ping(); // Ping immediately on page load — don't wait for first interval
            }
        })();
    </script>
@endif