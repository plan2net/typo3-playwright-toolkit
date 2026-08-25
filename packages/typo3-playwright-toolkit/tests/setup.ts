// Every request to the extension carries the API secret, and resolving it reads
// the consumer's filesystem. Tests have no prepared project, so hand them one.
process.env.PLAYWRIGHT_TOOLKIT_SECRET ||= 'secret-for-tests'
