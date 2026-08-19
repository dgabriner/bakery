# Catalog image sources

Temporary catalog imagery refreshed on 2026-08-07. The production catalog has 54 products; every product now has a locally stored primary image under `uploads/product_photos/catalog/`, with one distinct `product<ID>-distinct.jpg` asset per product. The second pass used product-name-aware tags and unique locks, then manually replaced obvious mismatches with product/category-specific bakery photos.

| Category | Source page | Used for |
| --- | --- | --- |
| Country sourdough | [Sourdough Country Loaf](https://sourdough.me/products/sourdough-country-loaf) | Sourdough loaf, rolls, and mondo loaves |
| Batard | [Pain au Levain Batard](https://balthazar-bakery.myshopify.com/products/r-breads-levain-batard) | Available for future batard-specific products |
| Baguette | [Crusty Artisan Bread](https://latelier-du-pain.com/english/menu_hard.html) | Baguette products |
| Bagel | [Artisan Bagels](https://nycbakerydirect.com/products/bagels-assorted) | Plain, poppy, sesame, and salt bagels |
| Pretzel | [King Soft Pretzel](https://www.gfifoods.com/1594-proppeller-king-soft-pretzel) | Pretzel rolls and pretzels |
| Sandwich loaf | [Homemade Sandwich Bread](https://vintagekitchennotes.com/white-sandwich-bread/) | Pan de Mie and white loaves |
| Whole wheat | [Organic Wholemeal](https://bakertom.co.uk/products/organic-100-wholemeal) | Whole Wheat |
| Dinner rolls | [Dinner Rolls](https://www.thebreadgal.com/collections/dinner-rolls) | Dinner Roll |
| Concha | [Mexican Conchas](https://successiblelife.com/es/receta-de-conchas-mexicanas/) | Conchas |
| Pan dulce assortment | [Mexican Pan Dulce](https://borderzine.com/2022/10/how-the-pan-dulce-supply-chain-shortage-made-me-appreciate-the-art-of-making-sweet-bread/) | Pan dulce products without a more specific image category |
| Cinnamon rolls | [Tray of Cinnamon Rolls](https://www.doublebatchbakery.com/product/tray-of-six-cinnamon-rolls-pre-order-/1963) | Roles de Canela |
| Potato bread | [Pan de Patata](https://bonviveur.com/es/recetas/pan-de-patata) | Potato Bread and Potato Roll |
| Ciabatta | [Sourdough Ciabatta](https://shop.sharon-bakery.com/products/a-package-of-4-soft-and-airy-sourdough-ciabbata-roles-br) | Ciabatta |

The distinct importer and product-name matching live in [scripts/catalog_sweep.php](../scripts/catalog_sweep.php). Some fallback images come from [LoremFlickr](https://loremflickr.com/) with product-specific bakery tags; direct-source repairs were used where the fallback result was visibly unrelated. These are still temporary reference images: replace them with owned or properly licensed bakery photography before public launch.
