erDiagram

    USER ||--|| ROLE : "assigned role"
    ROLE ||--o{ ROLE_PERMISSION : "defines"

    USER ||--o{ LEAD : "assigned to"
    USER ||--o{ LEAD_INTERACTION : "performs"
    USER ||--o{ FOLLOW_UP : "performs"
    USER ||--o{ TASK : "handles"
    USER ||--o{ ACTIVITY_LOG : "performs"
    USER ||--o{ EXTERNAL_OPERATION : "triggers"
    USER ||--o{ SUBSCRIPTION_DELIVERY : "supplies"

    SCHOOL ||--o| LEAD : "originates"
    SCHOOL ||--o| CUSTOMER : "becomes"
    SCHOOL ||--o{ CONTACT : "employs"

    LEAD_SOURCE ||--o{ LEAD : "categorizes"
    STATUS_DEFINITION ||--o{ LEAD : "defines status"

    LEAD ||--o{ LEAD_INTERACTION : "records"
    LEAD ||--o{ FOLLOW_UP : "requires"
    LEAD ||--o{ TASK : "requires"
    LEAD ||--o| CUSTOMER : "converts to"
    LEAD }o--o{ PROGRAM : "expresses interest"

    STATUS_DEFINITION ||--o{ CUSTOMER : "defines status"
    CUSTOMER ||--o{ CONTACT : "maintains"
    CUSTOMER ||--o{ DEAL : "purchases"
    CUSTOMER ||--o{ SUBSCRIPTION : "owns"
    CUSTOMER ||--o{ MATERIAL_DELIVERY : "receives"
    CUSTOMER ||--o{ MAILING_MEMBERSHIP : "joins"
    CUSTOMER ||--o{ TASK : "requires"

    STATUS_DEFINITION ||--o{ DEAL : "defines status"

    DEAL }o--o| PROGRAM : "purchases"
    DEAL }o--o| BUNDLE : "purchases"
    DEAL ||--o{ DOCUMENT : "generates"
    DEAL ||--o{ PAYMENT : "receives"
    DEAL ||--o{ TASK : "requires"
    DEAL ||--o| SUBSCRIPTION : "creates"

    BUNDLE }o--o{ PROGRAM : "contains"

    DOCUMENT_TEMPLATE ||--o{ DOCUMENT : "produces"
    DOCUMENT_TEMPLATE ||--o{ DOCUMENT_TEMPLATE_FIELD : "defines"
    DOCUMENT ||--o{ DOCUMENT_LINE : "contains"
    DOCUMENT ||--o{ DOCUMENT_RECIPIENT : "sends to"
    CONTACT ||--o{ DOCUMENT_RECIPIENT : "receives"
    BUSINESS_ENTITY ||--o{ DOCUMENT : "issues"
    DOCUMENT ||--o{ DOCUMENT : "precedes"
    STATUS_DEFINITION ||--o{ DOCUMENT : "defines status"

    EXPENSE ||--o| DOCUMENT : "has invoice"
    EXPENSE ||--o{ EXTERNAL_OPERATION : "triggers"
    DOCUMENT ||--o{ EXTERNAL_OPERATION : "triggers"

    PAYMENT_METHOD ||--o{ PAYMENT : "used for"
    PAYMENT ||--o| RECEIPT : "produces"
    STATUS_DEFINITION ||--o{ PAYMENT : "defines status"
    RECEIPT ||--o| DOCUMENT : "is represented by"

    SUBSCRIPTION ||--o{ SUBSCRIPTION_DELIVERY : "includes"
    PROGRAM ||--o{ SUBSCRIPTION_DELIVERY : "is supplied as"
    STATUS_DEFINITION ||--o{ SUBSCRIPTION : "defines status"

    PROGRAM ||--o{ MATERIAL_DELIVERY : "is delivered in"
    MATERIAL_DELIVERY ||--o{ MATERIAL_DELIVERY_RECIPIENT : "sent to"
    CONTACT ||--o{ MATERIAL_DELIVERY_RECIPIENT : "receives"
    MATERIAL_DELIVERY ||--o{ MATERIAL_DELIVERY_ATTACHMENT : "includes"
    STATUS_DEFINITION ||--o{ MATERIAL_DELIVERY : "defines status"

    MAILING_LIST ||--o{ MAILING_MEMBERSHIP : "contains"
    PROGRAM ||--o{ MAILING_LIST : "uses"
    SUPPLIER ||--o{ MAILING_MEMBERSHIP : "joins"

    SUPPLIER ||--o{ EXPENSE : "incurs"
    PROGRAM ||--o{ EXPENSE : "is charged for"
    STATUS_DEFINITION ||--o{ EXPENSE : "defines status"

    EMAIL_TEMPLATE ||--o{ EMAIL_TEMPLATE_FIELD : "defines"

    EXTERNAL_INTEGRATION_SETTING ||--o{ EXTERNAL_OPERATION : "configures"
    EXTERNAL_OPERATION ||--o{ ACTIVITY_LOG : "records"

    SCHOOL ||--o{ ACTIVITY_LOG : "owns"
    LEAD ||--o{ ACTIVITY_LOG : "owns"
    CUSTOMER ||--o{ ACTIVITY_LOG : "owns"
    DEAL ||--o{ ACTIVITY_LOG : "owns"
    SUBSCRIPTION ||--o{ ACTIVITY_LOG : "owns"
    MATERIAL_DELIVERY ||--o{ ACTIVITY_LOG : "owns"
    TASK ||--o{ ACTIVITY_LOG : "owns"
    DOCUMENT ||--o{ ACTIVITY_LOG : "owns"
    EXPENSE ||--o{ ACTIVITY_LOG : "owns"

    USER {
        string id PK
        string role_id FK
        string name
        string email
        string personal_email
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    ROLE {
        string id PK
        string name
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    ROLE_PERMISSION {
        string id PK
        string role_id FK
        string resource
        string action
        boolean is_allowed
        datetime created_at
        datetime updated_at
    }

    STATUS_DEFINITION {
        string id PK
        string scope
        string name
        boolean is_active
        integer sort_order
        datetime created_at
        datetime updated_at
    }

    SCHOOL {
        string id PK
        string name
        string phone
        string email
        string address
        datetime created_at
        datetime updated_at
    }

    LEAD_SOURCE {
        string id PK
        string name
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    LEAD {
        string id PK
        string school_id FK
        string assigned_user_id FK
        string lead_source_id FK
        string status_id FK
        string email
        string phone
        string sub_status
        datetime converted_at
        datetime created_at
        datetime updated_at
    }

    LEAD_INTERACTION {
        string id PK
        string lead_id FK
        string user_id FK
        string interaction_type
        text summary
        text result
        datetime occurred_at
        datetime created_at
        datetime updated_at
    }

    FOLLOW_UP {
        string id PK
        string lead_id FK
        string user_id FK
        datetime occurred_at
        text summary
        text result
        datetime next_at
        datetime created_at
        datetime updated_at
    }

    TASK {
        string id PK
        string user_id FK
        string lead_id FK
        string customer_id FK
        string deal_id FK
        string task_type
        string status
        string title
        text description
        datetime due_at
        datetime completed_at
        datetime created_at
        datetime updated_at
    }

    CUSTOMER {
        string id PK
        string school_id FK
        string lead_id FK
        string status_id FK
        datetime converted_at
        datetime created_at
        datetime updated_at
    }

    CONTACT {
        string id PK
        string school_id FK
        string customer_id FK
        string name
        string email
        string phone
        boolean is_primary
        boolean is_accounting_contact
        datetime created_at
        datetime updated_at
    }

    PROGRAM {
        string id PK
        string name
        text description
        decimal price
        boolean is_premium
        boolean is_subscription_type "true = program only sold as an annual subscription (creates SUBSCRIPTION on purchase)"
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    BUNDLE {
        string id PK
        string name
        text description
        decimal price
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    DEAL {
        string id PK
        string customer_id FK
        string program_id FK
        string bundle_id FK
        string status_id FK
        decimal agreed_amount
        decimal program_price_snapshot
        decimal bundle_price_snapshot
        string program_name_snapshot
        string bundle_name_snapshot
        string payment_method_id FK
        text special_request
        datetime purchased_at
        datetime completed_at
        datetime created_at
        datetime updated_at
    }

    DOCUMENT_TEMPLATE {
        string id PK
        string document_type
        string name
        text content
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    DOCUMENT_TEMPLATE_FIELD {
        string id PK
        string document_template_id FK
        string name
        string field_type
        string linked_field
        boolean is_required
        integer sort_order
        datetime created_at
        datetime updated_at
    }

    DOCUMENT {
        string id PK
        string deal_id FK
        string expense_id FK
        string document_template_id FK
        string business_entity_id FK
        string preceding_document_id FK
        string document_type
        string status_id FK
        string format
        string file_reference
        datetime sent_at
        datetime received_at
        datetime signed_at
        datetime created_at
        datetime updated_at
    }

    DOCUMENT_LINE {
        string id PK
        string document_id FK
        string description
        decimal quantity
        decimal unit_price
        decimal amount
        integer sort_order
        datetime created_at
        datetime updated_at
    }

    DOCUMENT_RECIPIENT {
        string id PK
        string document_id FK
        string contact_id FK
        string recipient_name
        string recipient_email
        datetime sent_at
        datetime created_at
        datetime updated_at
    }

    BUSINESS_ENTITY {
        string id PK
        string name
        enum classification "עוסק פטור, עוסק מורשה, חברה בעמ"
        string company_number
        string email
        string phone
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    PAYMENT_METHOD {
        string id PK
        string name
        string type
        datetime created_at
        datetime updated_at
    }

    PAYMENT {
        string id PK
        string deal_id FK
        string payment_method_id FK
        string status_id FK
        decimal amount
        string check_status
        date payment_date
        date cleared_date
        datetime created_at
        datetime updated_at
    }

    RECEIPT {
        string id PK
        string payment_id FK
        string document_id FK
        string status
        boolean issued_before_payment
        datetime issued_at
        datetime created_at
        datetime updated_at
    }

    SUBSCRIPTION {
        string id PK
        string customer_id FK
        string deal_id FK
        string status_id FK
        date start_date
        date end_date
        date cancelled_at
        decimal agreed_price
        decimal cancellation_credit
        datetime created_at
        datetime updated_at
    }

    SUBSCRIPTION_DELIVERY {
        string id PK
        string subscription_id FK
        string program_id FK
        string supplied_by FK
        integer sequence_number
        boolean is_supplied
        date supplied_at
        datetime created_at
        datetime updated_at
    }

    MATERIAL_DELIVERY {
        string id PK
        string customer_id FK
        string program_id FK
        string status_id FK
        datetime sent_at
        datetime opened_at
        datetime acknowledged_at
        datetime created_at
        datetime updated_at
    }

    MATERIAL_DELIVERY_RECIPIENT {
        string id PK
        string material_delivery_id FK
        string contact_id FK
        string recipient_name
        string recipient_email
        datetime created_at
        datetime updated_at
    }

    MATERIAL_DELIVERY_ATTACHMENT {
        string id PK
        string material_delivery_id FK
        string file_reference
        string file_name
        datetime created_at
        datetime updated_at
    }

    MAILING_LIST {
        string id PK
        string name
        string list_type
        string program_id FK
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    MAILING_MEMBERSHIP {
        string id PK
        string mailing_list_id FK
        string customer_id FK
        string supplier_id FK
        string membership_status
        datetime joined_at
        datetime removed_at
        datetime created_at
        datetime updated_at
    }

    SUPPLIER {
        string id PK
        string name
        string company_number
        string classification
        string phone
        string email
        text notes
        datetime created_at
        datetime updated_at
    }

    EXPENSE {
        string id PK
        string supplier_id FK
        string program_id FK
        string document_id FK
        string status_id FK
        decimal amount
        date expense_date
        text notes
        datetime created_at
        datetime updated_at
    }

    EMAIL_TEMPLATE {
        string id PK
        string name
        string template_type
        text subject
        text content
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    EMAIL_TEMPLATE_FIELD {
        string id PK
        string email_template_id FK
        string name
        string field_type
        string linked_field
        boolean is_required
        integer sort_order
        datetime created_at
        datetime updated_at
    }

    EXTERNAL_INTEGRATION_SETTING {
        string id PK
        string system
        boolean is_active
        json settings
        datetime created_at
        datetime updated_at
    }

    EXTERNAL_OPERATION {
        string id PK
        string document_id FK
        string expense_id FK
        string material_delivery_id FK
        string integration_setting_id FK
        string triggered_by_user_id FK
        string system
        string operation_type
        string trigger_source
        string status
        string external_reference
        text error_message
        datetime attempted_at
        datetime completed_at
        datetime created_at
        datetime updated_at
    }

    ACTIVITY_LOG {
        string id PK
        string school_id FK
        string lead_id FK
        string customer_id FK
        string deal_id FK
        string subscription_id FK
        string material_delivery_id FK
        string task_id FK
        string document_id FK
        string expense_id FK
        string external_operation_id FK
        string user_id FK
        string activity_type
        text description
        json metadata
        datetime occurred_at
        datetime created_at
        datetime updated_at
    }