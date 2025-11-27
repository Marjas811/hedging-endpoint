**# Hedging Endpoint (PrestaShop)**



\## Zakres

Endpoint udostępnia zamówienia z bazy PrestaShop w formacie JSON na potrzeby Cloud Function. Obsługuje:

\- filtrowanie zamówień po `last\_imported\_id` i `limit`,

\- wyliczenie wag (kg/oz) oraz ostatniego kursu metalu (24h lookback),

\- pomijanie zamówień z uwagą zawierającą frazę „lbma”.



\## Wymagania

\- PHP ≥ 5.6 z rozszerzeniem PDO MySQL.

\- Dostęp do bazy sklepu (parametry w `data/config/hedging-db.php` lub zmienne `HEDGING\_DB\_\*`).

\- Token API (w pliku `data/config/tokens.php` – komentarz `hedging endpoint` – lub zmienna `HEDGING\_ENDPOINT\_TOKEN`).

\- Logi wraz z błędami zapisywane są do `hedging-endpoint.log` .



\## Konfiguracja

1\. \*\*Parametry DB\*\*  

&nbsp;  - Plik `data/config/hedging-db.php` (tablica z `host`, `dbname`, `username`, `password`, `charset`).  

&nbsp;  - Alternatywnie ustaw zmienne środowiskowe:  

&nbsp;    `HEDGING\_DB\_HOST`, `HEDGING\_DB\_NAME`, `HEDGING\_DB\_USER`, `HEDGING\_DB\_PASSWORD`, `HEDGING\_DB\_CHARSET`.



2\. \*\*Token\*\*  

&nbsp;  - `data/config/tokens.php` – wpis:

&nbsp;    ```php

&nbsp;    return \[

&nbsp;      'TWÓJ\_TOKEN' => \['comment' => 'hedging endpoint', 'active' => true],

&nbsp;    ];

&nbsp;    ```

&nbsp;  - Albo `HEDGING\_ENDPOINT\_TOKEN` w środowisku.



3\. \*\*Filtrowanie LBMA\*\*  

&nbsp;  - Domyślnie pomijamy zamówienia, w których `km\_message.message` zawiera „lbma”.  

&nbsp;  - Aby zmienić wzorzec, edytuj podzapytanie w sekcji `LEFT JOIN (...) flagged`.



4\. \*\*Limit historii\*\*  

&nbsp;  - `MAX\_HISTORY\_DAYS = 7`. Można zwiększyć jedynie na potrzeby diagnostyki; pamiętaj o powrocie do 7 dni.



\## Uruchomienie lokalne

1\. Skopiuj plik do katalogu serwera (np. XAMPP `htdocs/hedging-endpoint.php`).

2\. Upewnij się, że Apache/PHP ma dostęp do DB i tokenu.

3\. Test:

&nbsp;  ```bash

&nbsp;  curl -H "Authorization: Bearer <token>" \\

&nbsp;    "http://localhost/hedging-endpoint.php?last\_imported\_id=0\&limit=5"

&nbsp;  ```\*\_


