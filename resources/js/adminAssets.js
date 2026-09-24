/**
 * Stylesheets only the admin panel needs.
 *
 * These used to be imported from app.js, so every shopper downloaded them on
 * the first page they opened - Font Awesome alone is ~100 KB of CSS, and the
 * storefront uses the Iconly set instead. Public Sans is the admin typeface;
 * the shop uses Urbanist.
 *
 * Imported by the backend layout components, which are async, so this lands in
 * the admin chunk and is fetched the first time an /admin screen renders.
 */
import "../../public/themes/default/fonts/public/public.css";
import "../../public/themes/default/fonts/fontawesome/fontawesome.css";
