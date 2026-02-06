import _ from "lodash";
import axios from "axios";

// Using local JSON files from public/data directory
// Using full URL to bypass axios baseURL (/api/)
const DISTRICTS_URL = window.location.origin + "/data/bd-districts.json";
const UPAZILAS_URL = window.location.origin + "/data/bd-upazilas.json";

export const frontendCountryStateCity = {
    namespaced: true,
    state: {
        countries: [],
        districts: [],
        upazilas: []
    },
    getters: {
        countries: function(state) {
            return state.countries;
        }
    },
    actions: {
        countries: function (context, payload) {
            return new Promise((resolve, reject) => {
                // Return Bangladesh as the only country
                const bangladeshCountry = [{ name: "Bangladesh", status: 5 }];
                context.commit("countries", bangladeshCountry);
                resolve({ data: { data: bangladeshCountry } });
            });
        },
        
        statesByCountry: function (context, payload) {
            return new Promise((resolve, reject) => {
                // Load districts from GitHub JSON
                axios.get(DISTRICTS_URL).then((res) => {
                    const districts = res.data.districts.map(district => ({
                        id: district.id,
                        name: district.name,
                        bn_name: district.bn_name,
                        status: 5
                    }));
                    context.commit("districts", districts);
                    resolve({ data: { data: districts } });
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        
        citiesByState: function (context, payload) {
            return new Promise((resolve, reject) => {
                // Load upazilas from GitHub JSON
                axios.get(UPAZILAS_URL).then((res) => {
                    // Find the district from context.state.districts
                    const district = context.state.districts.find(d => d.name === payload);
                    
                    if (!district) {
                        // If district not found in state, reload districts
                        axios.get(DISTRICTS_URL).then((districtRes) => {
                            const districts = districtRes.data.districts;
                            const matchedDistrict = districts.find(d => d.name === payload);
                            
                            if (!matchedDistrict) {
                                resolve({ data: { data: [] } });
                                return;
                            }
                            
                            // Filter upazilas by district_id
                            const upazilas = res.data.upazilas
                                .filter(upazila => upazila.district_id === matchedDistrict.id)
                                .map(upazila => ({
                                    id: upazila.id,
                                    name: upazila.name,
                                    bn_name: upazila.bn_name,
                                    status: 5
                                }));
                            
                            context.commit("upazilas", upazilas);
                            resolve({ data: { data: upazilas } });
                        }).catch(err => reject(err));
                    } else {
                        // Filter upazilas by district_id
                        const upazilas = res.data.upazilas
                            .filter(upazila => upazila.district_id === district.id)
                            .map(upazila => ({
                                id: upazila.id,
                                name: upazila.name,
                                bn_name: upazila.bn_name,
                                status: 5
                            }));
                        
                        context.commit("upazilas", upazilas);
                        resolve({ data: { data: upazilas } });
                    }
                }).catch((err) => {
                    reject(err);
                });
            });
        }
    },
    mutations: {
        countries: function(state, payload) {
            state.countries = payload;
        },
        districts: function(state, payload) {
            state.districts = payload;
        },
        upazilas: function(state, payload) {
            state.upazilas = payload;
        }
    },
};
