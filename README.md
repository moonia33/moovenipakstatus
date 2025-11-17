# moovenipakstatus

 Versija: 0.1.4

## Atsisiųsti

[![Download ZIP](https://img.shields.io/badge/Download-moovenipakstatus.zip-2ea44f?style=for-the-badge)](https://github.com/moonia33/moovenipakstatus/releases/latest/download/moovenipakstatus.zip)

Arba tiesioginė nuoroda: [Download moovenipakstatus.zip (latest release)](https://github.com/moonia33/moovenipakstatus/releases/latest/download/moovenipakstatus.zip)

# Git repo:

https://github.com/moonia33/moovenipakstatus.git


PrestaShop module that automatically updates order statuses based on Venipak (mijoravenipak) shipment tracking.

## Cron URL

After installation, open module configuration to see the generated cron URL with secret token.

Call it periodically, e.g. every 30–60 minutes, to refresh Venipak shipment statuses and apply configured scenarios.

## CLI runner (recommended on PS 9)

You can run the module logic from CLI to avoid front-controller routing issues:

```
php modules/moovenipakstatus/bin/cron.php
php modules/moovenipakstatus/bin/cron.php --track-only
php modules/moovenipakstatus/bin/cron.php --apply-only
php modules/moovenipakstatus/bin/cron.php --sleep=30  # wait 30s between track and apply
php modules/moovenipakstatus/bin/track.php            # track-only helper
```

Options:

- `--limit=NUMBER` – override max orders per run
- `--force` – vykdyti net jei modulis išjungtas BO
- `--verbose` – spausdinti scenarijus ir papildomą eigą
- `--track-only` – atlikti tik tracking atnaujinimą
- `--apply-only` – atlikti tik būsenų keitimą pagal scenarijus
- `--sleep=SECONDS` – palaukti tarp tracking ir apply (viename bėgime)

Back-office nustatymas „Delay between track and apply (seconds)“ naudojamas tada, kai CLI nepateikiamas `--sleep`. Palikite `0`, jei pauzės nereikia, arba nustatykite, pvz., `60`–`120` sekundžių.

Example crontab entry:

```
*/30 * * * * /usr/bin/php /home/liviacorsetti/htdocs/liviacorsetti.lt/modules/moovenipakstatus/bin/cron.php >/dev/null 2>&1
# Alternatyviai: atskirti į du cron su tarpu
*/15 * * * * /usr/bin/php /home/liviacorsetti/htdocs/liviacorsetti.lt/modules/moovenipakstatus/bin/cron.php --track-only >/dev/null 2>&1
*/15 * * * * (sleep 90; /usr/bin/php /home/liviacorsetti/htdocs/liviacorsetti.lt/modules/moovenipakstatus/bin/cron.php --apply-only >/dev/null 2>&1) &
```

## Atsisiuntimas (GitHub Releases)

- Kiekvieno žymėjimo (tag `v*`) metu automatiškai sugeneruojamas ZIP: `moovenipakstatus.zip`.
- Eikite į repo „Releases“ ir parsisiųskite naujausią ZIP.

Žymos kūrimas ir stūmimas:

```
git tag v0.1.0 -m "v0.1.0"
git push -u origin main
git push --tags
```
