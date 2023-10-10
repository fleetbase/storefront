export default function getNotificationSchemas() {
    const schemas = {
        apn: {
            key_id: '',
            team_id: '',
            app_bundle_id: '',
            private_key_content: '',
            production: true,
        },
        fcm: {
            firebase_credentials_json: '',
            firebase_database_url: '',
            firebase_project_name: '',
        },
    };

    return schemas;
}
