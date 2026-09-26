import data from '../../../public/data/bd-districts.json';

// All 64 districts, A-Z. `name` is the stored value and must stay exactly as it
// is in the JSON: shipping order areas match an address's district by string,
// on the storefront and again on the server (OrderTotals), so renaming one here
// would silently drop every address in it to the default shipping cost.
const bdDistricts = data.districts
    .map(d => ({ name: d.name, bn_name: d.bn_name }))
    .sort((a, b) => a.name.localeCompare(b.name));

export default bdDistricts;
