{{-- Web Cron Heartbeat: auto-ping server at configurable interval --}}
{{-- Only active when web cron is enabled in System Settings --}}
@if(\App\Services\WebCron\WebCronManager::isEnabled())
    <script>
        (function () {
            const INTERVAL = @json(\App\Services\WebCron\WebCronManager::getInterval() * 1000); // ms
            const url = @json(route('api.web-cron-ping'));
            const token = @json(\App\Services\WebCron\WebCronManager::generateToken());
            const backgroundMode = @json(\App\Services\WebCron\WebCronManager::isBackgroundEnabled());

            let consecutiveErrors = 0;
            const MAX_RETRIES = 5;
            let worker = null;
            let fallbackTimer = null;

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

            /**
             * Create a Web Worker from an inline script.
             * Web Worker timers are NOT throttled by browsers when tab is hidden.
             */
            function createWorker() {
                const blob = new Blob([
                    'const INTERVAL=' + INTERVAL + ';' +
                    'let timer=null;' +
                    'self.onmessage=function(e){' +
                        'if(e.data==="start"){' +
                            'if(timer)clearInterval(timer);' +
                            'timer=setInterval(function(){self.postMessage("tick")},INTERVAL);' +
                        '}' +
                        'if(e.data==="stop"){' +
                            'if(timer){clearInterval(timer);timer=null;}' +
                        '}' +
                    '};'
                ], { type: 'application/javascript' });

                try {
                    const w = new Worker(URL.createObjectURL(blob));
                    w.onmessage = function () { ping(); };
                    return w;
                } catch (e) {
                    return null;
                }
            }

            function start() {
                if (worker || fallbackTimer) return;

                // Try Web Worker first (not throttled in background tabs)
                worker = createWorker();
                if (worker) {
                    worker.postMessage('start');
                } else {
                    // Fallback to setInterval (throttled in background)
                    fallbackTimer = setInterval(ping, INTERVAL);
                }
            }

            function stop() {
                if (worker) {
                    worker.postMessage('stop');
                    worker.terminate();
                    worker = null;
                }
                if (fallbackTimer) {
                    clearInterval(fallbackTimer);
                    fallbackTimer = null;
                }
            }

            // Visibility handling: pause/resume based on background mode setting
            document.addEventListener('visibilitychange', function () {
                if (backgroundMode) {
                    // Background mode ON: never stop, ping when tab becomes visible again
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