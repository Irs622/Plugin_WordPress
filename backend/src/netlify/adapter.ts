import type { IncomingMessage, RequestListener, ServerResponse } from 'node:http';
import { Readable, Writable } from 'node:stream';

/**
 * Bridges a Web API Request (used by Netlify Functions v2, Edge, and Fetch API)
 * to a Node.js RequestListener (createApp), returning a standard Web API Response.
 * Zero external dependencies.
 */
export async function handleWebRequest(
  webRequest: Request,
  listener: RequestListener,
): Promise<Response> {
  const urlObj = new URL(webRequest.url);
  let pathname = urlObj.pathname;
  if (pathname.startsWith('/.netlify/functions/api')) {
    pathname = pathname.replace('/.netlify/functions/api', '/api');
  }
  const pathnameAndSearch = pathname + urlObj.search;

  const rawHeaders: Record<string, string> = {};
  webRequest.headers.forEach((val, key) => {
    rawHeaders[key.toLowerCase()] = val;
  });

  const bodyBuffer = Buffer.from(await webRequest.arrayBuffer());

  // Construct IncomingMessage-compatible readable stream
  const reqStream = new Readable({
    read() {
      this.push(bodyBuffer.length > 0 ? bodyBuffer : null);
      if (bodyBuffer.length > 0) {
        this.push(null);
      }
    },
  }) as unknown as IncomingMessage;

  reqStream.url = pathnameAndSearch;
  reqStream.method = webRequest.method;
  reqStream.headers = rawHeaders;
  reqStream.complete = true;
  reqStream.rawHeaders = Object.entries(rawHeaders).flat();

  return new Promise<Response>((resolve, reject) => {
    let statusCode = 200;
    const responseHeaders = new Headers();
    const chunks: Buffer[] = [];
    let headersSent = false;

    const resStream = new Writable({
      write(chunk: Buffer, _encoding, callback) {
        chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
        callback();
      },
    }) as unknown as ServerResponse;

    Object.defineProperty(resStream, 'headersSent', {
      get() {
        return headersSent;
      },
    });

    Object.defineProperty(resStream, 'statusCode', {
      get() {
        return statusCode;
      },
      set(code: number) {
        statusCode = code;
      },
    });

    resStream.writeHead = function (status: number, ...args: unknown[]) {
      statusCode = status;
      headersSent = true;
      let headersObj: Record<string, string | string[]> | undefined;
      for (const arg of args) {
        if (typeof arg === 'object' && arg !== null) {
          headersObj = arg as Record<string, string | string[]>;
          break;
        }
      }
      if (headersObj) {
        for (const [key, value] of Object.entries(headersObj)) {
          if (Array.isArray(value)) {
            value.forEach((v) => responseHeaders.append(key, v));
          } else if (value !== undefined) {
            responseHeaders.set(key, String(value));
          }
        }
      }
      return resStream;
    };

    resStream.setHeader = function (name: string, value: string | string[]) {
      if (Array.isArray(value)) {
        responseHeaders.delete(name);
        value.forEach((v) => responseHeaders.append(name, v));
      } else {
        responseHeaders.set(name, String(value));
      }
      return resStream;
    };

    resStream.getHeader = function (name: string) {
      return responseHeaders.get(name) ?? undefined;
    };

    resStream.hasHeader = function (name: string) {
      return responseHeaders.has(name);
    };

    resStream.removeHeader = function (name: string) {
      responseHeaders.delete(name);
    };

    const originalEnd = resStream.end.bind(resStream);
    resStream.end = function (chunk?: unknown, ...args: unknown[]) {
      if (chunk) {
        chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk)));
      }
      headersSent = true;
      originalEnd(...(args as [() => void]));

      const fullBody = Buffer.concat(chunks);
      resolve(
        new Response(fullBody.length > 0 ? fullBody : null, {
          status: statusCode,
          headers: responseHeaders,
        }),
      );
      return resStream;
    };

    try {
      listener(reqStream, resStream);
    } catch (err) {
      reject(err);
    }
  });
}

