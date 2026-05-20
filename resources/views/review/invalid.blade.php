<!doctype html>
<html lang="id">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>{{ $title ?? 'Tidak dapat dibuka' }}</title>
        <style>
            body {
                margin: 0;
                font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
                background: #f8fafc;
                color: #0f172a;
            }
            .container {
                max-width: 640px;
                margin: 0 auto;
                padding: 20px 16px 40px;
            }
            .card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
                padding: 18px 16px;
            }
            .title {
                font-weight: 800;
                font-size: 18px;
            }
            .muted {
                margin-top: 8px;
                color: #475569;
                font-size: 14px;
                line-height: 1.5;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card">
                <div class="title">{{ $title ?? 'Tidak dapat dibuka' }}</div>
                <div class="muted">{{ $message ?? 'Link tidak valid atau sudah tidak berlaku.' }}</div>
            </div>
        </div>
    </body>
</html>

