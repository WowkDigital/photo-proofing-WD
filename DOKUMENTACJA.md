# Dokumentacja Aplikacji: System Wyboru Zdjęć (Photo Proofing)

## 1. Opis Projektu
Aplikacja **Photo Proofing** służy do profesjonalnego udostępniania galerii i albumów fotograficznych klientom w celu dokonania selekcji ujęć (tzw. proofing) do dalszej obróbki i retuszu. System zapewnia fotografowi pełną kontrolę nad sesjami, opcjonalne szyfrowanie end-to-end po stronie klienta oraz natychmiastowe powiadomienia o złożonych zamówieniach (m.in. przez Telegram).

---

## 2. Architektura i Technologie
Aplikacja działa w lekkiej architekturze klient-serwer, nie wymagając instalacji ciężkich frameworków ani dedykowanego serwera bazodanowego:

- **Backend**: PHP 7.4+ (Vanilla PHP, PDO, cURL, GD)
- **Baza danych**: SQLite 3 z aktywnymi kluczami obcymi (`PRAGMA foreign_keys = ON`) i indeksami wydajnościowymi
- **Frontend**: 
  - HTML5 / Modern Vanilla JavaScript (ES6+)
  - **Tailwind CSS** (CDN)
  - **Lucide Icons**
  - **Canvas-Confetti**
- **Bezpieczeństwo**:
  - Opcjonalne szyfrowanie symetryczne AES-GCM (zdjęcia `.enc` szyfrowane i deszyfrowane w przeglądarce za pomocą Web Crypto API)
  - Sejf kluczy administratora (`admin_vault`) zabezpieczony hasłem admina i dedykowaną solą (`VAULT_SALT`)
  - Ochrona CSRF, zabezpieczenia sesji (SameSite=Lax, HttpOnly), rate-limiting logowania i formularza wyboru
  - Blokady bezpośredniego dostępu do plików `.sqlite`, `.log`, `.enc`, `.sh` w `.htaccess`

---

## 3. Struktura Katalogów i Plików

```text
├── admin/                     # Panel Administratora
│   ├── auth.php               # Moduł kontroli sesji i autoryzacji panelu
│   ├── diagnostics.php        # Centrum diagnostyki i audytu bezpieczeństwa
│   ├── index.php              # Główny dashboard (zarządzanie albumami i sesjami)
│   ├── login.php              # Logowanie administratora
│   ├── logout.php             # Wylogowanie administratora
│   ├── settings.php           # Edycja parametrów galerii i linków kontaktowych
│   ├── upload.php             # Masowy upload zdjęć z szyfrowaniem i miniaturkami
│   ├── vault_api.php          # API bezpiecznego sejfu kluczy szyfrujących
│   └── view_album.php         # Podgląd i edycja konkretnego albumu
├── api/                       # Warstwa Backend & API JSON
│   ├── check_auth.php         # Sprawdzanie uprawnień dostępu dla żądań klienta
│   ├── config.php             # Ładowanie ustawień z bazy SQLite (generowany przy instalacji)
│   ├── config.php.example     # Wzorcowy plik konfiguracyjny
│   ├── crypto_helper.php      # Narzędzia kryptograficzne (OpenSSL, PBKDF2)
│   ├── csrf.php               # Generator i walidator tokenów CSRF
│   ├── db.php                 # Połączenie PDO SQLite i automatyczne migracje
│   ├── get_all_selections.php # Pobieranie historii wyborów klientów
│   ├── get_config.php         # Publiczna konfiguracja albumu i linki kontaktowe
│   ├── init_db.php            # Skrypt inicjalizujący schemat bazy
│   ├── list_files.php         # Pobieranie listy plików danego albumu
│   ├── logger.php             # Rejestrator zdarzeń do bazy i plików
│   ├── rebuild_db.php         # Bezpieczny reset bazy (chroniony POST i CSRF)
│   ├── save_selection.php     # Obsługa i walidacja zapisu wyboru klienta
│   ├── serve_image.php        # Kontrolowane serwowanie zaszyfrowanych plików
│   ├── sync_photos.php        # Narzędzie synchronizacji plików z bazą
│   └── telegram_notify.php    # Moduł powiadomień Telegram Bot
├── assets/                    # Zasoby statyczne aplikacji
│   └── images/
│       └── logo.png           # Logotyp
├── data/                      # Folder bazy SQLite (chroniony przed dostępem HTTP)
│   ├── .htaccess              # Blokada Deny from all
│   └── database.sqlite        # Baza danych
├── photos/                    # Przechowywanie zaszyfrowanych plików zdjęć (.enc)
│   └── thumbnails/            # Miniaturki (.enc)
├── scripts/                   # Skrypty narzędziowe i wdrożeniowe
│   └── update_production.sh   # Automatyczna kopia zapasowa i aktualizacja z Git
├── selection_logs/            # Logi zapasowe wyborów (chronione przed HTTP)
├── album.html                 # Główny interfejs klienta (grid, lightbox, selekcja)
├── index.php                  # Strona powitalna galerii
├── install.php                # Kreator instalacji (z opcją samousunięcia)
├── login.php                  # Logowanie klienta do zabezpieczonej galerii
├── logout.php                 # Wylogowanie klienta
├── .htaccess                  # Konfiguracja serwera Apache i hardening
├── DOKUMENTACJA.md            # Dokumentacja techniczna
└── README.md                  # Skrócony opis projektu
```

---

## 4. Baza Danych (Schemat Tabel)

Baza danych SQLite składa się z następujących tabel:

1. **`settings`**: Klucz-wartość parametrów globalnych (`ALBUM_TITLE`, `ADMIN_PASSWORD_HASH`, `TELEGRAM_*`, `CONTACT_*`, `VAULT_SALT`).
2. **`albums`**: Informacje o albumach (`id`, `slug`, `internal_name`, `public_title`, `encryption_key_hash`, `created_at`).
3. **`photos`**: Przesłane pliki zdjęć (`id`, `filename`, `original_filename`, `upload_date`, `album_id`).
4. **`selections`**: Zamówienia klientów (`id`, `client_name`, `client_email`, `client_phone`, `client_notes`, social media, `ip_address`, `album_id`, `status`).
5. **`selected_photos`**: Relacja wybranych zdjęć do zamówienia (`selection_id`, `photo_filename`).
6. **`admin_vault`**: Zabezpieczony sejf kluczy szyfrujących albumów (`id`, `key_hash`, `encrypted_data`, `iv`, `tag`, `created_at`).
7. **`logs`**: Dziennik zdarzeń audytowych i błędów (`id`, `event_type`, `message`, `details`, `ip_address`, `created_at`).

---

## 5. Instalacja i Bezpieczeństwo Instancji

### Standardowa procedura instalacji:
1. Skopiuj pliki aplikacji na serwer z obsługą **PHP 7.4+** i modułami `pdo_sqlite`, `gd`, `curl`.
2. Upewnij się, że katalogi `data/`, `photos/`, `photos/thumbnails/` oraz `selection_logs/` mają uprawnienia do zapisu dla procesu serwera WWW (`chmod 755` lub `chmod 775`).
3. Otwórz w przeglądarce adres instalatora: `https://twojadomena.pl/install.php`.
4. Wprowadź tytuł galerii, hasło administratora oraz opcjonalne dane integracji Telegram i linki kontaktowe.
5. Pozostaw zaznaczoną opcję **„Automatycznie usuń install.php po zakończeniu (zalecane)”**.
6. Kliknij **Zakończ Instalację**.

### Usuwanie i ochrona plików instalacyjnych:
- **Automatyczne samousunięcie:** Jeśli w formularzu zaznaczono opcję usuwania, instalator po pomyślnym utworzeniu bazy automatycznie kasuje plik `install.php` z dysku (`unlink`).
- **Ręczne usunięcie z ekranu instalatora:** Jeśli instalator nie został skasowany automatycznie, na ekranie sukcesu dostępny jest przycisk *„Usuń install.php i przejdź do panelu”*.
- **Audyt w Panelu Administratora:** W module [admin/diagnostics.php](file:///c:/Users/kw314/Documents/AG_codes/photo-proofing-WD/admin/diagnostics.php) znajduje się stały monitor obecności pliku `install.php`. Jeśli plik nadal istnieje, wyświetla się ostrzeżenie o wysokim priorytecie oraz przycisk pozwalający skasować instalator jednym kliknięciem.

---

## 6. Procedura Aktualizacji w Środowisku Produkcyjnym

Do bezpiecznej aktualizacji kodu służy skrypt [scripts/update_production.sh](file:///c:/Users/kw314/Documents/AG_codes/photo-proofing-WD/scripts/update_production.sh):

```bash
bash scripts/update_production.sh
```

Skrypt automatycznie:
1. Wykrywa katalog główny projektu.
2. Tworzy skompresowaną kopię zapasową całej aplikacji i bazy danych w katalogu `backups/` z pominięciem ciężkich plików zdjęć i repozytorium `.git`.
3. Wykonuje dodatkową kopię pliku `data/database.sqlite`.
4. Opcjonalnie pobiera najnowsze zmiany za pomocą `git pull origin main`.
5. W przypadku błędu podaje dokładną komendę do natychmiastowego przywrócenia instancji z archiwum.
