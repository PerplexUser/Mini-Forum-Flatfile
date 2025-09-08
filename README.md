# Mini-Forum-Flatfile
Hier ist ein kompaktes, lauffähiges Mini‑Forum in reinem PHP, das Flatfiles (JSON‑Dateien) in einem Verzeichnis verwendet. Es kommt ohne Datenbank aus, nutzt Dateisperren (flock) gegen Race Conditions, schützt gegen XSS mit htmlspecialchars, bringt ein einfaches CSRF‑Token mit und kann 1:1 auf (fast) jedem PHP‑Webspace laufen.
