// Reproducible mechanical data extraction. Run with Node 22; never receives user data.
import { mkdir, writeFile } from 'node:fs/promises';
import { gunzipSync } from 'node:zlib';
import { createHash } from 'node:crypto';

const version = 'v3.2-export.7';
const root = `https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/${version}`;
const output = new URL('../public/data/card-geography/', import.meta.url);
async function get(url) {
    const response = await fetch(url);
    if (!response.ok) throw new Error(`Geography download failed: ${response.status}`);
    return Buffer.from(await response.arrayBuffer());
}
const [countryBytes, stateBytes, cityBytes, license] = await Promise.all([
    get(`${root}/json/countries.json`),
    get(`${root}/json/states.json`),
    get(`https://github.com/dr5hn/countries-states-cities-database/releases/download/${version}/json-cities.json.gz`),
    get(`${root}/LICENSE`),
]);
const hash = (bytes) => createHash('sha256').update(bytes).digest('hex');
if (hash(cityBytes) !== '311575e7b90512b15cc1ba6c9e897b3e8f3905a9c6e09db344a7dc1cac1ba2f5') throw new Error('City checksum mismatch');
const countries = JSON.parse(countryBytes);
const states = JSON.parse(stateBytes);
const cities = JSON.parse(gunzipSync(cityBytes));
const place = (item) => ({
    value: item.name,
    names: Object.fromEntries(['zh-CN', 'ms', 'es'].filter((locale) => item.translations?.[locale]).map((locale) => [locale, item.translations[locale]])),
});
await mkdir(output, { recursive: true });
async function save(name, value) {
    await writeFile(new URL(name, output), `${JSON.stringify(value)}\n`);
}
await save('countries.json', countries.filter((c) => /^[A-Z]{2}$/.test(c.iso2)).map((c) => ({ code: c.iso2, name: c.name, phone: c.phonecode.replace(/[^0-9]/g, '') })));
for (const country of countries) {
    if (!/^[A-Z]{2}$/.test(country.iso2)) continue;
    const rows = states.filter((s) => s.country_code === country.iso2).map((state) => {
        const options = [...new Map(cities.filter((city) => city.state_id === state.id).map((city) => [city.name, place(city)])).values()];
        // City-states/territories use their city name when the source has no smaller locality.
        const metropolitan = { HK: 'Hong Kong', MO: 'Macao', SG: 'Singapore' }[country.iso2];
        if (!options.length && metropolitan) options.push({ value: metropolitan, names: {} });
        // Source v3.2 confuses Anhui's Fuyang with Zhejiang's Fuyang district.
        if (country.iso2 === 'CN' && state.name === 'Anhui') {
            const fuyang = options.find((city) => city.value === 'Fuyang');
            if (fuyang) fuyang.names['zh-CN'] = '阜阳市';
        }
        return { ...place(state), cities: options };
    });
    // The Provider accepts canonical place names, not administrative IDs/levels.
    // Merge same-name subdivisions and preserve all their cities as unique choices.
    const uniqueStates = new Map();
    for (const row of rows) {
        const previous = uniqueStates.get(row.value);
        if (previous) previous.cities = [...new Map([...previous.cities, ...row.cities].map((city) => [city.value, city])).values()];
        else uniqueStates.set(row.value, row);
    }
    await save(`${country.iso2}.json`, [...uniqueStates.values()]);
}
await writeFile(new URL('LICENSE.txt', output), license);
await save('manifest.json', { source: 'https://github.com/dr5hn/countries-states-cities-database', version, license: 'ODbL-1.0', countriesSha256: hash(countryBytes), statesSha256: hash(stateBytes), citiesArchiveSha256: hash(cityBytes), countries: countries.map((c) => c.iso2) });
console.log(`Imported ${countries.length} countries, ${states.length} subdivisions, ${cities.length} cities. Public derivative data retains ODbL-1.0.`);
