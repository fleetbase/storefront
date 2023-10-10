export default function createShareableLink(path, queryParams = {}) {
    // Get the current URL
    const currentUrl = window.location.href;

    // Create a URL object from the current URL
    const url = new URL(currentUrl);

    // Replace the existing path with the provided path
    url.pathname = path;

    // Clear any existing query parameters
    url.search = '';

    // Add the provided query parameters
    for (const [key, value] of Object.entries(queryParams)) {
        url.searchParams.append(key, value);
    }

    // Return the new URL as a string
    return url.toString();
}
