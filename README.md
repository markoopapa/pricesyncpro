Price Sync Pro – PrestaShop Árszinkronizációs Modul
A Price Sync Pro egy robusztus, API-alapú megoldás PrestaShop webáruházak árainak valós idejű szinkronizálására. Kifejezetten olyan hálózatokhoz készült, ahol több bolt (pl. román és magyar piac) árait kell egy központi beszállítótól vagy egy láncolaton keresztül frissíteni.

🚀 Főbb jellemzők
Többszintű szerepkörök: Választható működési módok (Beszállító, Lánc/Köztes, Végpont).

Valós idejű frissítés: Hook-alapú szinkronizáció (mentéskor azonnal küldi az adatot).

Tömeges szinkronizálás: Beépített Bulk Sync funkció (20-as batch feldolgozás) a teljes termékkészlet egyszeri frissítéséhez.

Intelligens árszámítás:

Bruttó alapú küldés, automatikus nettósítás a fogadó oldalon.

Pénznem-érzékeny kerekítés (HUF esetén 5-re kerekítés, RON esetén 2 tizedesjegy).

Egyedi szorzók alkalmazása minden szinten.

Tiltólista (Blacklist): Bizonyos termékek kizárása az automatikus frissítésből (kézi árazás megtartása).

Részletes naplózás: Admin felületen követhető események, hibaüzenetek és sikeres tranzakciók.

🛠 Telepítés
Töltsd fel a pricesyncpro mappát a PrestaShop /modules könyvtárába.

Telepítsd a modult az admin felületen (Modulkezelő).

A modul automatikusan létrehozza a szükséges adatbázis táblákat:

ps_pricesyncpro_blacklist (Tiltólista)

ps_pricesyncpro_logs (Naplózás)

Mód,Leírás
OFF,A modul inaktív.
SENDER,Beszállító: Termékmentéskor küldi az árat a megadott cél URL-ekre.
CHAIN,"Lánc: Fogadja az árat, alkalmazza a saját szorzóját, majd továbbküldi a következő boltnak."
RECEIVER,Végpont: Fogadja az árat és frissíti a helyi adatbázist.

🔑 Konfiguráció
A működéshez minden résztvevő boltban azonos API Token beállítása szükséges.

URL Formátum
A cél URL-eknek minden esetben a következő végpontra kell mutatniuk:
https://webshopod.hu/module/pricesyncpro/api

Tiltólista használata
Amennyiben egy terméket az adott boltban manuálisan szeretnél árazni, add hozzá a Saját Cikkszámot (Reference) a Tiltólistához. Ez megakadályozza, hogy a "fentről" jövő API hívás felülírja az árat, de a kézi mentés továbbra is továbbküldi az adatot a láncban lefelé.

🛡 Biztonság és Stabilitás
Duplikáció elleni védelem: Statikus változók használatával a modul megakadályozza a végtelen ciklusokat és a dupla mentéseket.

Hibakezelés: Az API try-catch blokkokkal van ellátva, így egy esetleges szerverhiba (HTTP 500) nem akasztja meg a webshop működését.

SSL Barát: CURL beállítások az SSL ellenőrzés áthidalására (szükség esetén).

📁 Fájlszerkezet
pricesyncpro.php: Fő osztály, hook-ok és admin logika.

controllers/front/api.php: Az API végpont, amely fogadja és feldolgozza a bejövő kéréseket.

views/templates/admin/configure.tpl: Az adminisztrációs felület (Dashboard, Config, Log).

📝 Licenc
Ez a modul egyedi fejlesztés, üzleti felhasználásra készült.
