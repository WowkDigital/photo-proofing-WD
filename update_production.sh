#!/bin/bash
# Skrypt do bezpiecznej aktualizacji aplikacji Photo Proofing w środowisku produkcyjnym
# Służy do wykonania kopii zapasowej przed wdrożeniem nowych plików oraz do ewentualnego przywrócenia zmian.

# Konfiguracja
BACKUP_DIR="backups"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/backup_${TIMESTAMP}.tar.gz"
DB_FILE="data/database.sqlite"
EXCLUDE_DIRS="--exclude=.git --exclude=${BACKUP_DIR} --exclude=photos"

# Kolory do wypisywania w konsoli
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Rozpoczynam proces aktualizacji aplikacji...${NC}"

# Krok 1: Weryfikacja i tworzenie katalogu kopii zapasowej
if [ ! -d "$BACKUP_DIR" ]; then
    echo "Folder ${BACKUP_DIR} nie istnieje. Tworzenie..."
    mkdir -p "$BACKUP_DIR"
fi

# Krok 2: Tworzenie kopii zapasowej plików oraz bazy danych
echo -e "${YELLOW}Tworzenie kopii zapasowej całej aplikacji (pomijanie ciężkich zdjęć, .git oraz samych backupów)...${NC}"
tar -czvf "$BACKUP_FILE" $EXCLUDE_DIRS . > /dev/null 2>&1

if [ $? -eq 0 ]; then
    echo -e "${GREEN}Sukces: Kopia zapasowa zapisana jako ${BACKUP_FILE}${NC}"
else
    echo -e "${RED}Błąd: Nie udało się utworzyć kopii zapasowej. Przerwano aktualizację!${NC}"
    exit 1
fi

# Krok 3: (Opcjonalnie) Kopia samej bazy dla pewności, gdyby pliki bazy wymagały osobnego traktowania
if [ -f "$DB_FILE" ]; then
    cp "$DB_FILE" "${BACKUP_DIR}/database_${TIMESTAMP}.sqlite"
    echo -e "${GREEN}Sukces: Zabezpieczono dodatkową, osobną kopię bazy danych w: ${BACKUP_DIR}/database_${TIMESTAMP}.sqlite${NC}"
else
    echo -e "${YELLOW}Ostrzeżenie: Plik bazy danych ($DB_FILE) nie istnieje (może to świeża instalacja?)${NC}"
fi

# Krok 4: Wdrażanie zmian z GIT (opcjonalne, włącz jeśli używasz)
echo -e "${YELLOW}Czy chcesz pobrać najnowsze zmiany z repozytorium git (git pull)? [T/n]${NC}"
read -p "" response
response=${response,,}    # do małych liter

if [[ "$response" =~ ^(tak|t|yes|y|)$ ]]; then
    echo -e "${YELLOW}Pobieranie najnowszych zmian z repozytorium...${NC}"
    git pull origin main
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}Aktualizacja plików powiodła się!${NC}"
    else
        echo -e "${RED}Błąd: Nie udało się zaktualizować plików z Git. Zmiany nie zostały wdrożone prawidłowo.${NC}"
        echo -e "${YELLOW}Aby przywrócić z backupu, użyj polecenia:${NC}"
        echo -e "tar -xzvf ${BACKUP_FILE} -C ./"
        exit 1
    fi
else
    echo -e "${YELLOW}Pominięto automatyczne pobieranie (git pull). Rozpakuj nowe pliki ręcznie.${NC}"
fi

# Krok 5: Instrukcje na wypadek błędu
echo -e "${GREEN}Aktualizacja zakończona bezpiecznie.${NC}"
echo -e "${YELLOW}Jeśli cokolwiek nie działa, przywróć aplikację przed aktualizacją wydając komendę:${NC}"
echo -e "tar -xzvf ${BACKUP_FILE} -C ./"
