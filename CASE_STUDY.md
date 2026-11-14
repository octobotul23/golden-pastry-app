# Studiu de caz — Golden Pastry · platformă de comenzi

> Varianta completă, cu filmul de prezentare: **https://iluz.ro/video/proiect/h-mw8lo682** · [English](https://iluz.ro/video/proiect/h-mw8lo682?lang=en)

| | |
|---|---|
| **Client** | Golden Pastry (patiserie, București) |
| **An** | 2026 |
| **Livrat** | site public de comenzi, zona de client, panourile interne, scripturi de instalare și actualizare pe VPS, suita de teste, filme de prezentare RO/EN |
| **Tehnologii** | HTML, CSS, JavaScript, PHP 8, MySQL · Claude Design, Claude Code, HyperFrames |
| **Realizat de** | [Iluz Studios](https://iluz.ro) — Octavian Ples |

## Contextul

Am pornit de la un prototip făcut în Claude Design, care ținea toate datele în browser (`localStorage`). Arăta bine, dar nu putea funcționa în realitate: o comandă dată de pe telefonul clientului nu ajungea niciodată pe ecranul din laborator. Cerințele: setările care se schimbă să stea în configurare, nu în cod; o modificare făcută într-un loc să apară peste tot unde e folosită; meniuri simple pe telefon.

## Ce am construit

- **28 august 2026:** prototipul a primit un server PHP cu MySQL — 12 tabele, 7 module, 22 de produse. Prețurile vin din baza de date, iar stocul se calculează din mișcările de inventar.
- **Conturi și securitate:** roluri, sesiuni pe server, limitarea încercărilor de autentificare, jurnal de audit.
- **Notificări pe trei canale:** email, WhatsApp și Telegram; comenzile pot fi acceptate direct din Telegram.
- **Versiunile 2.x:** coduri de recomandare, pontaj cu QR sau NFC, statistici, recomandă din nou, registru de casă.
- **Calitate:** fiecare reparație vine cu un test care pică dacă reparația e scoasă; toate problemele intră într-un registru. Mai mulți agenți Claude au lucrat în paralel, fiecare pe propria bază de date izolată.
- **Film de prezentare:** făcut cu HyperFrames din 15 înregistrări de ecran, doar cu date fictive, verificat de două ori cadru cu cadru.

## Rezultate

- **19 versiuni în mai puțin de două luni** (până la v2.7.6, 19 octombrie 2026).
- **717 probleme înregistrate, 664 rezolvate**; restul lăsate deschise intenționat.
- Cifrele despre comenzi și timpul economisit vor fi adăugate după o perioadă de folosire.

---

### English summary

An order platform for a Bucharest pastry shop, grown from a browser-only Claude Design prototype into a multi-user PHP 8 / MySQL app: public store, customer area, lab, driver, warehouse, admin, time-tracking and stats panels, with email/WhatsApp/Telegram notifications. 19 releases in under two months; 664 of 717 logged issues fixed, each fix backed by a regression test. Full case study and demo film: https://iluz.ro/video/proiect/h-mw8lo682?lang=en
