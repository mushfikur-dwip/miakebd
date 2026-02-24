import data from '../../../public/data/bd-districts.json';

const bdDistricts = data.districts.map(d => ({ name: d.name }));

export default bdDistricts;
