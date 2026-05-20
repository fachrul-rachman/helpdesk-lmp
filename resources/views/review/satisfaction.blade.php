<!doctype html>
<html lang="id">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Review Kepuasan</title>
        <style>
            :root {
                --gold: #f59e0b;
                --slate-900: #0f172a;
                --slate-700: #334155;
                --slate-600: #475569;
                --slate-200: #e2e8f0;
                --slate-50: #f8fafc;
            }
            body {
                margin: 0;
                font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial,
                    "Apple Color Emoji", "Segoe UI Emoji";
                background: var(--slate-50);
                color: var(--slate-900);
            }
            .container {
                max-width: 640px;
                margin: 0 auto;
                padding: 20px 16px 40px;
            }
            .card {
                background: #fff;
                border: 1px solid var(--slate-200);
                border-radius: 16px;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
                overflow: hidden;
            }
            .card-header {
                padding: 16px 16px 12px;
                border-bottom: 1px solid var(--slate-200);
            }
            .title {
                font-weight: 700;
                font-size: 16px;
            }
            .subtitle {
                margin-top: 6px;
                font-size: 12px;
                color: var(--slate-600);
                line-height: 1.4;
            }
            .card-body {
                padding: 16px;
            }
            .label {
                font-size: 12px;
                font-weight: 700;
                color: var(--slate-700);
            }
            .muted {
                color: var(--slate-600);
                font-size: 12px;
            }
            .stars {
                display: inline-flex;
                gap: 6px;
                margin-top: 10px;
                user-select: none;
            }
            .star-btn {
                border: none;
                background: transparent;
                padding: 0;
                cursor: pointer;
                width: 38px;
                height: 38px;
                display: grid;
                place-items: center;
            }
            .star {
                width: 28px;
                height: 28px;
                fill: transparent;
                stroke: var(--gold);
                stroke-width: 2;
            }
            .star.filled {
                fill: var(--gold);
                stroke: var(--gold);
            }
            textarea {
                width: 100%;
                min-height: 120px;
                margin-top: 10px;
                border: 1px solid var(--slate-200);
                border-radius: 12px;
                padding: 10px 12px;
                font-size: 14px;
                outline: none;
                resize: vertical;
            }
            textarea:focus {
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
                border-color: #3b82f6;
            }
            .btn {
                width: 100%;
                margin-top: 14px;
                height: 44px;
                border: none;
                border-radius: 12px;
                background: #2563eb;
                color: #fff;
                font-weight: 700;
                font-size: 14px;
                cursor: pointer;
            }
            .btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .error {
                margin-top: 10px;
                padding: 10px 12px;
                border-radius: 12px;
                border: 1px solid #fecaca;
                background: #fef2f2;
                color: #b91c1c;
                font-size: 13px;
            }
            .ticket-meta {
                margin-top: 10px;
                padding: 10px 12px;
                border-radius: 12px;
                border: 1px solid var(--slate-200);
                background: #fff;
            }
            .ticket-number {
                font-size: 12px;
                font-weight: 800;
                color: var(--slate-600);
            }
            .ticket-subject {
                margin-top: 4px;
                font-size: 14px;
                font-weight: 700;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card">
                <div class="card-header">
                    <div class="title">Form Review Kepuasan</div>
                    <div class="subtitle">
                        Mohon berikan penilaian untuk membantu kami meningkatkan layanan.
                    </div>

                    <div class="ticket-meta">
                        <div class="ticket-number">{{ $ticket->ticket_number ?? 'Ticket' }}</div>
                        <div class="ticket-subject">{{ $ticket->subject }}</div>
                        <div class="muted" style="margin-top: 6px;">
                            Customer: {{ $customer?->name ?? 'Customer' }} ({{ $customer?->phone_number ?? '-' }})
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    @if ($errors->any())
                        <div class="error">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ url()->current() . '?' . request()->getQueryString() }}">
                        @csrf

                        <div class="label">Rating</div>
                        <div class="muted">Klik bintang untuk memilih 1-5.</div>

                        <div class="stars" id="stars" aria-label="Rating bintang">
                            @for ($i = 1; $i <= 5; $i++)
                                <button type="button" class="star-btn" data-value="{{ $i }}" aria-label="Bintang {{ $i }}">
                                    <svg class="star" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M12 17.3 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.3Z" />
                                    </svg>
                                </button>
                            @endfor
                        </div>

                        <input type="hidden" name="rating" id="rating" value="{{ old('rating', '') }}" />

                        <div style="margin-top: 16px;">
                            <div class="label">Ulasan (opsional)</div>
                            <div class="muted">Tulis feedback singkat yang bisa membantu kami.</div>
                            <textarea name="feedback" maxlength="2000" placeholder="Tulis ulasan kamu di sini...">{{ old('feedback', '') }}</textarea>
                        </div>

                        <button class="btn" type="submit" id="submitBtn" disabled>Kirim</button>
                    </form>
                </div>
            </div>
        </div>

        <script>
            (function () {
                var ratingInput = document.getElementById('rating');
                var starsEl = document.getElementById('stars');
                var submitBtn = document.getElementById('submitBtn');

                function setRating(value) {
                    ratingInput.value = String(value || '');
                    var stars = starsEl.querySelectorAll('.star');
                    for (var i = 0; i < stars.length; i++) {
                        var starValue = i + 1;
                        if (value && starValue <= value) stars[i].classList.add('filled');
                        else stars[i].classList.remove('filled');
                    }
                    submitBtn.disabled = !value;
                }

                starsEl.addEventListener('click', function (e) {
                    var btn = e.target.closest('button[data-value]');
                    if (!btn) return;
                    var value = Number(btn.getAttribute('data-value'));
                    setRating(value);
                });

                // Restore old value (validation fail)
                var oldValue = Number(ratingInput.value || 0);
                if (oldValue) setRating(oldValue);
            })();
        </script>
    </body>
</html>

