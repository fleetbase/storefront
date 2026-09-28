export default function getNotificationSchemas() {
    const schemas = {
        apn: {
            key_id: '',
            team_id: '',
            app_bundle_id: '',
            private_key_content: '',
            environment: 'auto',
        },
        fcm: {
            firebase_credentials_json: '',
            android_channel_id: '',
        },
    };

    return schemas;
}
