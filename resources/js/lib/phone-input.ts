import {
    getCountries,
    getCountryCallingCode,
    parsePhoneNumberFromString,
    type CountryCode,
} from 'libphonenumber-js/max';
const names = new Intl.DisplayNames(['en'], { type: 'region' });
export const dialCountries = getCountries().map((code) => ({
    code,
    name: names.of(code) ?? code,
    phone: getCountryCallingCode(code),
}));
export function normalizedPhone(value: string, region: string) {
    return parsePhoneNumberFromString(value, region as CountryCode)?.number ?? value;
}
