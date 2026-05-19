# World (fallback) region pack

This is the always-available fallback region pack. It contains no local data  - 
no MBTiles, no topo PDFs, no radio frequencies, no planting calendar overrides.

Modules that read from the active region pack will render generic content
when this pack is active. The Admin -> Region tab is the place to install or
switch to a real region pack.

This directory ships in the repo. `scripts/seed-builtin-regions.sh` copies it
(and any other repo-bundled packs) into `/var/lib/noosphere/regions/` on
deploy.
