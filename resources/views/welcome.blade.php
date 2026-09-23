<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="refresh" content="0; url={{ url('/') }}">
        <title>FORMADOC — Redirection</title>
        <script>window.location.replace('{{ url('/') }}');</script>
    </head>
    <body style="font-family:system-ui,sans-serif;background:#f5f6f8;color:#1b1b18;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
        <p>
            Redirection vers <a href="{{ url('/') }}" style="color:#2563eb">FORMADOC</a>…
        </p>
    </body>
</html>