import { createInterface } from 'node:readline';
import { runParkImage } from '../../worker/src/ops/image-runtime';

const bytes = (u: Uint8Array): string => Buffer.from(u).toString('base64');

for await (const line of createInterface({ input: process.stdin })) {
	if (line === '') continue;
	let reply: Record<string, unknown>;
	try {
		const out = await runParkImage(JSON.parse(Buffer.from(line, 'base64').toString('utf8')));
		reply = { bytes: bytes(out.bytes), width: out.width, height: out.height };
	} catch (e: unknown) {
		reply = { error: e instanceof Error ? e.message : String(e) };
	}
	process.stdout.write(JSON.stringify(reply) + '\n');
}
