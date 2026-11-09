#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include "gd.h"

static unsigned char *slurp(size_t *n)
{
	size_t cap = 1 << 20, len = 0;
	unsigned char *b = malloc(cap);
	size_t r;
	while ((r = fread(b + len, 1, cap - len, stdin)) > 0) {
		len += r;
		if (len == cap) b = realloc(b, cap *= 2);
	}
	*n = len;
	return b;
}

static void put32(uint32_t v)
{
	putchar(v >> 24);
	putchar((v >> 16) & 255);
	putchar((v >> 8) & 255);
	putchar(v & 255);
}

int main(int argc, char **argv)
{
	size_t n;
	unsigned char *in;
	if (argc == 7 && !strcmp(argv[1], "write")) {
		int w = atoi(argv[2]), h = atoi(argv[3]), cs = atoi(argv[4]);
		int fmt = atoi(argv[5]), size = 0, x, y;
		gdImagePtr im = gdImageCreateTrueColor(w, h);
		in = slurp(&n);
		if (n != (size_t)w * h * 4) return 2;
		for (y = 0; y < h; y++)
			for (x = 0; x < w; x++) {
				unsigned char *p = in + ((size_t)y * w + x) * 4;
				im->tpixels[y][x] = (int)((uint32_t)p[0] << 24 | p[1] << 16 | p[2] << 8 | p[3]);
			}
		im->transparent = (int)strtol(argv[6], NULL, 10);
		void *out = gdImageGd2Ptr(im, cs, fmt, &size);
		if (!out) return 3;
		fwrite(out, 1, size, stdout);
		return 0;
	}
	if (argc == 2 && !strcmp(argv[1], "read")) {
		int x, y;
		gdImagePtr im;
		in = slurp(&n);
		im = gdImageCreateFromGd2Ptr((int)n, in);
		if (!im) return 3;
		put32(im->sx);
		put32(im->sy);
		for (y = 0; y < im->sy; y++)
			for (x = 0; x < im->sx; x++) put32((uint32_t)gdImageGetTrueColorPixel(im, x, y));
		return 0;
	}
	return 1;
}
