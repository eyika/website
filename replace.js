const fs = require('fs');
const filePath = '/Users/basttyy/Documents/Sites/Backend/Atomdocs/app/docs/beta/routing.md';

fs.readFile(filePath, 'utf8', (err, data) => {
    if (err) {
        console.error(err);
        return;
    }

    const pattern = /'(\w+Controller)@(\w+)'/g;
    const result = data.replace(pattern, (match, p1, p2) => {
        return `[${p1}::class, '${p2}']`;
    });

    fs.writeFile(filePath, result, 'utf8', (err) => {
        if (err) {
            console.error(err);
            return;
        }
        console.log('Replacement complete.');
    });
});