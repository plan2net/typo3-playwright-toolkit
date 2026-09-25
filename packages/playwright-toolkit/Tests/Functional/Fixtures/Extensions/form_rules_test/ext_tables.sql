CREATE TABLE tx_formrulestest_record (
    title varchar(255) DEFAULT '' NOT NULL,
    kind varchar(255) DEFAULT 'plain' NOT NULL,
    subtitle varchar(255) DEFAULT '' NOT NULL,
    tags varchar(255) DEFAULT 'a' NOT NULL,
    amount int(11) DEFAULT '5' NOT NULL,
    stock int(11) DEFAULT '0' NOT NULL,
    code varchar(255) DEFAULT '' NOT NULL,
    secret varchar(255) DEFAULT '' NOT NULL,
    fixed varchar(255) DEFAULT '' NOT NULL,
    note varchar(255) DEFAULT '' NOT NULL,
    shared varchar(255) DEFAULT '' NOT NULL,
    bonus varchar(255) DEFAULT '' NOT NULL,
    children int(11) unsigned DEFAULT '0' NOT NULL,
    settings mediumtext
);

CREATE TABLE tx_formrulestest_child (
    title varchar(255) DEFAULT '' NOT NULL,
    label varchar(255) DEFAULT '' NOT NULL,
    subitems int(11) unsigned DEFAULT '0' NOT NULL,
    parentid int(11) unsigned DEFAULT '0' NOT NULL,
    parentchild int(11) unsigned DEFAULT '0' NOT NULL,
    parenttable varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(255) DEFAULT '' NOT NULL,
    sorting_foreign int(11) DEFAULT '0' NOT NULL
);
