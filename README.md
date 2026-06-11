# Forme Sales Analytics

Árbevétel- és eladás-analitika a forme.hu WooCommerce áruházhoz. Saját, könnyű plugin – nem a GA4-et duplikálja, hanem a **WooCommerce rendelési adatokból** épít gyors, interaktív riportot az admin felületen.

## Mit tud

- **KPI kártyák**: bruttó árbevétel, rendelésszám, átlag kosárérték (AOV), termék/rendelés – mind az előző azonos hosszúságú időszakhoz hasonlítva (% változás).
- **Árbevétel idősor**: nap / hét / hónap bontás, oszlop = árbevétel, vonal = rendelésszám, a magyar kampánynapok (Anyák napja, Apák napja) jelölve.
- **Top designok Pareto-elemzéssel**: melyik 20% termék hozza a bevétel 80%-át.
- **Kategória-bontás**: melyik niche viszi a boltot.
- **Kosárméret & bundle-hatás**: 1 / 2 / 3+ pólós rendelések eloszlása + új vs. visszatérő vevő átlagértéke – pont a bundle-kedvezmény méréséhez.
- **Kuponteljesítmény**: melyik akció mennyi valós bevételt hozott, mennyi kedvezmény árán.

## Telepítés

1. Másold a teljes `forme-sales-analytics` mappát a `wp-content/plugins/` alá (vagy töltsd fel a zipet: **Bővítmények → Új hozzáadása → Bővítmény feltöltése**).
2. Aktiváld a WP admin **Bővítmények** menüjében. Aktiváláskor létrejön a két adattábla.
3. Nyisd meg a bal oldali **Forme Analytics** menüpontot.
4. Első alkalommal megjelenik egy fekete sáv **„Feltöltés indítása”** gombbal – ez tölti fel a meglévő rendeléseidből a riportot. Kattints rá, és várd meg a 100%-ot (kötegekben, 7 naponként halad, nem akad timeoutba).

Ezután minden új rendelés és státuszváltás automatikusan frissül.

## Hogyan számol

| Elv | Megvalósítás |
|---|---|
| Bevételnek számít | csak `completed` és `processing` státusz (szűrhető a `fsa_paid_statuses` filterrel) |
| Visszatérítés | levonva az `order->get_total_refunded()` alapján |
| Élő frissítés | `woocommerce_order_status_changed` hook → az érintett nap újraszámolása |
| Napi biztonsági futás | WP-Cron hajnali 3-kor újraaggregálja a tegnapi + mai napot |
| Teljesítmény | a dashboard csak az aggregált táblákat kérdezi, sosem a nyers rendeléseket |

## Adattáblák

- `wp_fsa_sales_daily` – napi + termékszintű bontás (árbevétel, db, kategória, kedvezmény)
- `wp_fsa_orders_summary` – rendelésenként 1 sor (AOV, darabszám, kupon, új/visszatérő)

A plugin **törlésekor** (nem csak deaktiváláskor) az `uninstall.php` eltávolítja a táblákat és opciókat.

## Testreszabás

```php
// Pl. a "fizetésre vár" (on-hold) is számítson bevételnek:
add_filter( 'fsa_paid_statuses', function ( $statuses ) {
    $statuses[] = 'on-hold';
    return $statuses;
} );
```

## Jogosultság

Minden REST-végpont és az admin oldal `manage_woocommerce` jogosultságot igényel, a hívások WP nonce-szal védettek.

## Fejlesztés (opcionális)

A React forrás a `app/` mappában van (külön, nem a pluginben). Build:

```bash
cd app && npm install && node build.mjs
```

A kimenet a plugin `admin/dist/app.js` és `app.css` fájljaiba kerül. A futtatáshoz nem kell semmit buildelned – a kész bundle a pluginben van.
