import { readFileSync } from 'node:fs';

export default new WebAssembly.Module(readFileSync(process.env['TINYIMG_WASM']!));
