# Dokumentacja Aplikacji: System Wyboru Zdjęć (Photo Proofing)

## 1. Opis Projektu
Aplikacja służy do profesjonalnego udostępniania albumów zdjęć klientom w celu dokonania przez nich wyboru fotografii do dalszej obróbki (tzw. photo proofing). System pozwala fotografowi na wgranie zdjęć, a klientowi na wygodne przeglądanie i przesyłanie informacji o wybranych kadrach.

## 2. Architektura i Technologie
Aplikacja została zbudowana w architekturze klient-serwer z wykorzystaniem następujących technologii:

- **Backend**: PHP 7.4+ (bez dodatkowych frameworków)
- **Baza danych**: SQLite 3 (lekka, nie wymaga osobnego serwera)
- **Frontend**: 
  - HTML5 / Vanilla JavaScript
  - **Tailwind CSS**: Stylowanie interfejsu (CDN)
  - **Lucide Icons**: Zestaw ikon
  - **Canvas-Confetti**: Efekty wizualne po złożeniu zamówienia
- **Bezpieczeństwo**: Opcjonalne szyfrowanie zdjęć metodą AES-GCM (deszyfrowanie po stronie klienta za pomocą klucza).

## 3. Struktura Katalogów
- `/admin`: Panel zarządzania (logowanie, wgrywanie zdjęć, przegląd zamówień).
- `/api`: Logika backendowa (endpointy JSON, obsługa bazy danych).
  - `config.php`: Główne ustawienia galerii i linki kontaktowe.
  - `db.php`: Inicjalizacja połączenia PDO.
  - `init_db.php`: Skrypt tworzący strukturę bazy danych.
  - `save-selection.php`: Obsługa zapisu wyboru klienta.
- `/data`: Miejsce przechowywania pliku bazy danych SQLite.
- `/photos`: Zdjęcia przesłane na serwer.
- `/selection_logs`: Tekstowe kopie zapasowe logów zamówień.
- `album.html`: Główny interfejs klienta.
- `login.php` / `logout.php`: Obsługa dostępu do galerii.

## 4. Główne Funkcjonalności

### Interfejs Klienta (`album.html`):
- **Grid Zdjęć**: Możliwość zmiany układu kolumn (od 2 do 6).
- **Lightbox**: Podgląd pełnoekranowy z nawigacją strzałkami i gestami (touch).
- **System Zaznaczania**: Wybieranie zdjęć jednym kliknięciem.
- **Formularz Zamówienia**: Zbieranie danych kontaktowych (Telegram, WhatsApp, Signal, Social Media) oraz notatek.
- **Walidacja**: Przycisk zapisu aktywny dopiero po podaniu imienia i przynajmniej jednej formy kontaktu.

### Panel Administratora (`/admin`):
- **Wgrywanie zdjęć**: Masowy upload plików bezpośrednio do bazy i folderu.
- **Zarządzanie Albumami**: Tworzenie nowych sesji dla różnych klientów.
- **Podgląd Wyborów**: Wyświetlanie listy zdjęć wybranych przez konkretnych klientów wraz z ich danymi.

### Konfiguracja (`api/config.php`):
- `ALBUM_TITLE`: Tytuł widoczny na górze strony.
- `PASSWORD_PROTECTION_ENABLED`: Włączenie/wyłączenie wymagania hasła dla klienta.
- `ADMIN_PASSWORD`: Hasło do panelu administratora.
- `CONTACT_*`: Linki do mediów społecznościowych i komunikatorów fotografa.

## 5. Baza Danych (Schema)
System korzysta z czterech głównych tabel:
1. `albums`: ID, slug, nazwa wewnętrzna, tytuł publiczny, klucz szyfrujący.
2. `photos`: ID, nazwa pliku, oryginalna nazwa, data uploadu, powiązanie z albumem.
3. `selections`: ID, dane klienta (imię, email, telefon, id social media), notatki, IP, data.
4. `selected_photos`: Powiązanie konkretnego zamówienia (selection) z wybranymi plikami zdjęć.

## 6. Instalacja i Uruchomienie
1. Skopiuj pliki na serwer z obsługą PHP 7.4+.
2. Upewnij się, że katalogi `/data`, `/photos` oraz `/selection_logs` mają uprawnienia do zapisu dla serwera WWW.
3. Uruchom skrypt `api/init_db.php` (np. przez przeglądarkę lub terminal PHP), aby utworzyć strukturę bazy danych.
4. Skonfiguruj hasło administratora w `api/config.php`.
5. Zaloguj się do panelu `/admin`, by wgrać pierwsze zdjęcia.

---
*Dokumentacja wygenerowana przez Antigravity.*
