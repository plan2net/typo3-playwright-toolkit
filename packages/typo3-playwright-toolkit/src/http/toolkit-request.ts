import type { APIRequestContext } from '@playwright/test'
import { SECRET_HEADER } from './api-secret.js'
import { TEST_ID_HEADER, browserHeaders } from '../contract.js'
import type { ToolkitConfig } from '../config.js'

const TOOLKIT_HEADERS = [TEST_ID_HEADER.toLowerCase(), SECRET_HEADER.toLowerCase()]
const SENDING_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'fetch']

/**
 * Playwright does not route an APIRequestContext, so the test ID has to be put on
 * here instead. No secret: it stays explicit, on the two requests that need it.
 */
export function toolkitRequest(
    request: APIRequestContext,
    config: ToolkitConfig,
    testId: string,
): APIRequestContext {
    const site = new URL(config.testingURL).origin

    const onSite = (target: unknown): boolean => {
        const url = 'string' === typeof target ? target : String((target as { url?: () => string })?.url?.() ?? '')

        if (!/^[a-z][a-z0-9+.-]*:/i.test(url)) {
            return true
        }

        try {
            return new URL(url).origin === site
        } catch {
            return false
        }
    }

    const headersFor = (target: unknown, given: Record<string, string> = {}): Record<string, string> => {
        if (onSite(target)) {
            return testId ? { ...given, ...browserHeaders(testId) } : { ...given }
        }

        return Object.fromEntries(
            Object.entries(given).filter(([name]) => !TOOLKIT_HEADERS.includes(name.toLowerCase())),
        )
    }

    return new Proxy(request, {
        get(target, property, receiver) {
            const value: unknown = Reflect.get(target, property, receiver)

            if ('function' !== typeof value) {
                return value
            }
            if ('string' !== typeof property || !SENDING_METHODS.includes(property)) {
                return (value as (...args: unknown[]) => unknown).bind(target)
            }

            return (url: unknown, options: { headers?: Record<string, string> } = {}) =>
                (value as (...args: unknown[]) => unknown).call(target, url, {
                    ...options,
                    headers: headersFor(url, options.headers),
                })
        },
    })
}
