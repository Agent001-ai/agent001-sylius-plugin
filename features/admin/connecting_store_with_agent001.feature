@agent001_connecting_store
Feature: Connecting the store with Agent001
    In order to answer customer questions with an AI assistant
    As an Administrator
    I want to connect the store with my Agent001 account

    Background:
        Given the store operates on a single channel in "United States"
        And I am logged in as an administrator

    @ui
    Scenario: Starting with the connection of a new store
        When I open the Agent001 assistant page
        Then the getting started guide should point to "Connect store"
        And I should be able to connect the store

    @ui
    Scenario: Connecting the store with an Agent001 account
        When I open the Agent001 assistant page
        And I connect the store
        Then I should be redirected to Agent001 to authorize the store
        When Agent001 confirms the connection
        Then I should be notified that the store has been connected
        And the getting started guide should point to "Assign an agent"
